<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

final readonly class ImpactHandler
{
    private const HEADER_TYPES = [
        'message_id',
        'subject',
        'spf_result',
        'dkim_result',
        'dmarc_result',
        'x_mailer',
        'return_path',
    ];

    private const FINANCIAL_TYPES = [
        'iban',
        'bic',
        'wallet_btc',
        'wallet_eth',
        'wallet_xmr',
        'credit_card',
        'phone',
    ];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Full impact summary with wasted_time, ioc_value, cost_efficiency, campaigns.
     *
     * Spec 096 / C2 — accepts optional `$scamType` to filter all metrics by
     * scam type code (e.g. INVOICE_FRAUD). When null, behavior is byte-identical
     * to the pre-spec-096 response (regression guarantee for `All` filter).
     *
     * @return array{wasted_time: array<string, mixed>, ioc_value: array<string, mixed>, cost_efficiency: array<string, mixed>, campaigns: array<string, mixed>}
     */
    public function getSummary(string $period = 'all', ?string $scamType = null): array
    {
        $threshold = $this->periodToThreshold($period);
        $scamType = $this->normalizeScamType($scamType);

        return [
            // Spec 108 — getWastedTime now also derives a period-aware
            // delta for the new "Scammer Replies Elicited" tile, needs
            // the raw $period string to compute the prev-period window.
            'wasted_time' => $this->getWastedTime($threshold, $scamType, $period),
            // Spec 106 — fresh_iocs window inside ioc_value follows the
            // page-level period selector (7d/30d/90d), falling back to 30d
            // for 'all' so the tile stays a meaningful velocity signal.
            'ioc_value' => $this->getIocValue($threshold, $scamType, $period),
            'cost_efficiency' => $this->getCostEfficiency($threshold, $scamType),
            'campaigns' => $this->getCampaigns($scamType),
            'trends' => $this->computeTrends($period, $scamType),
        ];
    }

    /**
     * Spec 096 / C2 — normalize the scam_type query param. Trims whitespace and
     * treats empty strings as null (no filter).
     */
    private function normalizeScamType(?string $scamType): ?string
    {
        if (null === $scamType) {
            return null;
        }

        $trimmed = trim($scamType);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Spec 096 / C2 — sub-query fragment selecting `scam_type_id` from
     * lkp_scam_type for the given code. Reusable across filters.
     */
    private function scamTypeIdLookupSubquery(): string
    {
        return '(SELECT scam_type_id FROM lkp_scam_type WHERE code = :scam_type)';
    }

    /**
     * IOC uniqueness analysis with summary, by_type breakdown, and daily_trend.
     *
     * @return array{summary: array<string, mixed>, by_type: list<array<string, mixed>>, daily_trend: list<array<string, mixed>>}
     */
    public function getIocUniqueness(string $period = '30d', ?string $iocType = null, ?string $scamType = null): array
    {
        $threshold = $this->periodToThreshold($period);
        $headerExclude = $this->headerExcludeClause();
        $typeFilter = '';
        $scamType = $this->normalizeScamType($scamType);

        if (null !== $iocType && '' !== $iocType) {
            /** @var string $quoted */
            $quoted = $this->connection->quote($iocType);
            $typeFilter = ' AND type = ' . $quoted;
        }

        $dateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';

        // Spec 096 / C3 — sub-query filter for scam_type via observed_ioc → message → conversation.
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];
        $scamIdSubquery = null !== $scamType
            ? ' AND indicator_id IN (SELECT DISTINCT oi.indicator_id FROM observed_ioc oi'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';

        // Summary
        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}{$scamIdSubquery}",
            $params,
        );

        $novelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}{$scamIdSubquery}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
            $params,
        );

        $novelPct = $totalIocs > 0 ? round($novelIocs * 100.0 / $totalIocs, 1) : 0.0;

        $summary = [
            'total_iocs' => $totalIocs,
            'novel_iocs' => $novelIocs,
            'novel_pct' => $novelPct,
        ];

        // By type
        $byTypeRows = $this->connection->fetchAllAssociative(
            'SELECT type, COUNT(*) as total,'
            . " COUNT(*) FILTER (WHERE enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal')) as novel"
            . " FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}{$scamIdSubquery}"
            . ' GROUP BY type ORDER BY total DESC',
            $params,
        );

        $byType = array_map(static function (array $row): array {
            $total = self::rowInt($row, 'total');
            $novel = self::rowInt($row, 'novel');

            return [
                'type' => self::rowStr($row, 'type'),
                'total' => $total,
                'novel' => $novel,
                'novel_pct' => $total > 0 ? round($novel * 100.0 / $total, 1) : 0.0,
            ];
        }, $byTypeRows);

        // Spec 096 / C5 — daily trend respects the period threshold when set;
        // falls back to 30 days when period='all'. Ensures "IOCs per Day" chart
        // narrows with the page-level period filter.
        $dailyTrendWindow = null !== $threshold ? "created_at >= {$threshold}" : "created_at > NOW() - INTERVAL '30 days'";
        $trendRows = $this->connection->fetchAllAssociative(
            'SELECT DATE(created_at) as date, COUNT(*) as total,'
            . " COUNT(*) FILTER (WHERE enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal')) as novel"
            . " FROM indicator WHERE {$headerExclude}{$typeFilter}"
            . " AND {$dailyTrendWindow}"
            . $scamIdSubquery
            . ' GROUP BY DATE(created_at) ORDER BY date ASC',
            $params,
        );

        $dailyTrend = array_map(static fn (array $row): array => [
            'date' => self::rowStr($row, 'date'),
            'total' => self::rowInt($row, 'total'),
            'novel' => self::rowInt($row, 'novel'),
        ], $trendRows);

        return [
            'summary' => $summary,
            'by_type' => $byType,
            'daily_trend' => $dailyTrend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getWastedTime(?string $threshold, ?string $scamType = null, string $period = 'all'): array
    {
        $dateFilter = null !== $threshold ? " AND ts_last >= {$threshold}" : '';
        $scamFilter = null !== $scamType ? ' AND scam_type_id = ' . $this->scamTypeIdLookupSubquery() : '';
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];

        // Spec 108 — resolve the inbound direction id once. dir_id varies
        // per DB (1/2 in prod, auto-incremented in fresh fixtures), same
        // pattern as ScammerEngagementCalculator::calculate.
        /** @var int|string|false $directionInRaw */
        $directionInRaw = $this->connection->fetchOne(
            "SELECT dir_id FROM lkp_direction WHERE code = 'in'",
        );
        $directionInId = $directionInRaw !== false ? (int) $directionInRaw : 1;

        // Spec 107 — qualified conversations only: turns_count >= 2 means
        // the scammer actually replied at least once. The 1-turn convs
        // (first email, no reply) contribute 0h to the sum but inflate
        // the denominator and avg_hours, making the headline framing
        // misleading. The filter does NOT change `total_hours` materially
        // (1-turn rows already sum to 0), but cleans up the conversation
        // count surfaced as the tile's subtitle.
        $row = $this->connection->fetchAssociative(
            'SELECT'
            . ' COALESCE(SUM(engagement_duration_sec), 0) / 3600.0 AS total_hours,'
            . ' COUNT(*) AS total_conversations,'
            . ' COALESCE(AVG(NULLIF(engagement_duration_sec, 0)), 0) / 3600.0 AS avg_hours,'
            . ' COALESCE(MAX(engagement_duration_sec), 0) / 3600.0 AS max_hours'
            . ' FROM conversation'
            . " WHERE status IN ('closed', 'open', 'abandoned')"
            . ' AND deleted_at IS NULL'
            . ' AND turns_count >= 2'
            . $dateFilter
            . $scamFilter,
            $params,
        );

        $row = \is_array($row) ? $row : [];

        // Scam type of the longest conversation
        // When a scam_type filter is active, the longest is by definition that one.
        if (null !== $scamType) {
            $longestScamType = $scamType;
        } else {
            $longestScamType = $this->connection->fetchOne(
                'SELECT st.code FROM conversation c'
                . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
                . ' WHERE c.engagement_duration_sec = (SELECT MAX(engagement_duration_sec) FROM conversation WHERE deleted_at IS NULL)'
                . ' LIMIT 1',
            );
        }

        // Spec 096 / C5 — weekly trend respects the period threshold when set;
        // falls back to 12 weeks when period='all'. Ensures the chart at the
        // bottom of the Impact page actually narrows when the user picks 7d/30d/90d.
        $trendWindow = null !== $threshold ? "ts_last >= {$threshold}" : "ts_last > NOW() - INTERVAL '12 weeks'";
        // Spec 107 — same qualified-conversation filter as the headline.
        $trendRows = $this->connection->fetchAllAssociative(
            "SELECT DATE_TRUNC('week', ts_last)::date AS week,"
            . ' SUM(engagement_duration_sec) / 3600.0 AS hours'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . ' AND turns_count >= 2'
            . " AND {$trendWindow}"
            . $scamFilter
            . " GROUP BY DATE_TRUNC('week', ts_last)"
            . ' ORDER BY week ASC',
            $params,
        );

        $weeklyTrend = array_map(static fn (array $r): array => [
            'week' => self::rowStr($r, 'week'),
            'hours' => round(self::rowFloat($r, 'hours'), 2),
        ], $trendRows);

        // Spec 108 — "Scammer Replies Elicited" tile: direct count of
        // inbound messages (direction='in') in qualified conversations.
        // Reframed from the previous time-based metric — see spec 108 for
        // the methodology audit that led to the switch from time → count.
        $scammerRepliesSqlBase = 'SELECT COUNT(m.msg_id) FROM message m'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE m.deleted_at IS NULL AND c.deleted_at IS NULL'
            . " AND c.status IN ('closed','open','abandoned')"
            . ' AND c.turns_count >= 2'
            . " AND m.direction = {$directionInId}";

        $scammerRepliesCount = $this->fetchInt(
            $scammerRepliesSqlBase
            . (null !== $threshold ? " AND c.ts_last >= {$threshold}" : '')
            . (null !== $scamType ? ' AND c.scam_type_id = ' . $this->scamTypeIdLookupSubquery() : ''),
            $params,
        );

        // Previous-period count: only meaningful for windowed periods;
        // for 'all' the delta concept doesn't apply, so prev=null and
        // the frontend hides the trend chip.
        $prevWindowDays = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => null,
        };

        if ($prevWindowDays !== null) {
            $doubleDays = $prevWindowDays * 2;
            $scammerRepliesPrevCount = $this->fetchInt(
                $scammerRepliesSqlBase
                . " AND c.ts_last >= NOW() - INTERVAL '{$doubleDays} days'"
                . " AND c.ts_last < NOW() - INTERVAL '{$prevWindowDays} days'"
                . (null !== $scamType ? ' AND c.scam_type_id = ' . $this->scamTypeIdLookupSubquery() : ''),
                $params,
            );
            $scammerRepliesDeltaPct = $scammerRepliesPrevCount > 0
                ? round(($scammerRepliesCount - $scammerRepliesPrevCount) / $scammerRepliesPrevCount * 100.0, 1)
                : null;
        } else {
            $scammerRepliesPrevCount = null;
            $scammerRepliesDeltaPct = null;
        }

        return [
            'total_hours' => round(self::rowFloat($row, 'total_hours'), 2),
            'total_conversations' => self::rowInt($row, 'total_conversations'),
            'avg_hours' => round(self::rowFloat($row, 'avg_hours'), 2),
            'max_hours' => round(self::rowFloat($row, 'max_hours'), 2),
            'longest_scam_type' => \is_string($longestScamType) ? $longestScamType : null,
            'weekly_trend' => $weeklyTrend,
            'scammer_replies_count' => $scammerRepliesCount,
            'scammer_replies_prev_count' => $scammerRepliesPrevCount,
            'scammer_replies_delta_pct' => $scammerRepliesDeltaPct,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getIocValue(?string $threshold, ?string $scamType = null, string $period = 'all'): array
    {
        $headerExclude = $this->headerExcludeClause();
        $dateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';
        $obsDateFilter = null !== $threshold ? " AND ts_observed >= {$threshold}" : '';

        // Spec 096 / C2 — when scam_type is provided, narrow the indicator set
        // to those observed in messages from conversations of that scam_type.
        // The sub-query is expensive on large windows; documented as a known
        // limitation. TODO: cache by scam_type if perf becomes an issue.
        $scamIdSubquery = null !== $scamType
            ? ' AND indicator_id IN (SELECT DISTINCT oi.indicator_id FROM observed_ioc oi'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';
        $obsScamFilter = null !== $scamType
            ? ' AND msg_id IN (SELECT m.msg_id FROM message m JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];

        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$dateFilter}{$scamIdSubquery}",
            $params,
        );

        $novelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$dateFilter}{$scamIdSubquery}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
            $params,
        );

        $financialIn = $this->inClause(self::FINANCIAL_TYPES);
        $financialIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE type IN ({$financialIn}){$dateFilter}{$scamIdSubquery}",
            $params,
        );

        $highConfidence = $this->fetchInt(
            "SELECT COUNT(*) FROM observed_ioc WHERE confidence_score >= 0.9{$obsDateFilter}{$obsScamFilter}",
            $params,
        );

        // By type (top 10)
        $byTypeRows = $this->connection->fetchAllAssociative(
            "SELECT type, COUNT(*) as count FROM indicator WHERE {$headerExclude}{$dateFilter}{$scamIdSubquery}"
            . ' GROUP BY type ORDER BY count DESC LIMIT 10',
            $params,
        );

        $byType = array_map(static fn (array $r): array => [
            'type' => self::rowStr($r, 'type'),
            'count' => self::rowInt($r, 'count'),
        ], $byTypeRows);

        $novelPct = $totalIocs > 0 ? round($novelIocs * 100.0 / $totalIocs, 1) : 0.0;

        // Spec 106 — "Fresh IOCs" honest replacement for the misleading
        // "Novel IOCs %". Window tracks the page-level period selector
        // (7d/30d/90d). When period='all', NO window applies —
        // window_days=null signals the frontend to render the cumulative
        // "Total IOCs" face of the tile instead (consistent with how
        // Criminal Time Wasted, Cost, and Actor Dedup behave on All).
        // Scam-type filter respected in both modes.
        $freshWindowDays = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => null,
        };

        if ($freshWindowDays !== null) {
            $prevWindowDays = $freshWindowDays * 2;
            $freshIocs = $this->fetchInt(
                "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}"
                . " AND first_seen >= NOW() - INTERVAL '{$freshWindowDays} days'"
                . $scamIdSubquery,
                $params,
            );
            $freshIocsPrev = $this->fetchInt(
                "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}"
                . " AND first_seen >= NOW() - INTERVAL '{$prevWindowDays} days'"
                . " AND first_seen < NOW() - INTERVAL '{$freshWindowDays} days'"
                . $scamIdSubquery,
                $params,
            );
            // Null (not 0) when prev window is empty — avoids falsely
            // claiming "▲ ∞%" or "▲ 100%" on a cold start.
            $freshIocsDeltaPct = $freshIocsPrev > 0
                ? round(($freshIocs - $freshIocsPrev) / $freshIocsPrev * 100.0, 1)
                : null;
        } else {
            $freshIocs = null;
            $freshIocsPrev = null;
            $freshIocsDeltaPct = null;
        }

        return [
            'total_iocs' => $totalIocs,
            'novel_iocs' => $novelIocs,
            'novel_pct' => $novelPct,
            'fresh_iocs_count' => $freshIocs,
            'fresh_iocs_prev_count' => $freshIocsPrev,
            'fresh_iocs_delta_pct' => $freshIocsDeltaPct,
            'fresh_iocs_window_days' => $freshWindowDays,
            'financial_iocs' => $financialIocs,
            'high_confidence' => $highConfidence,
            'by_type' => $byType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCostEfficiency(?string $threshold, ?string $scamType = null): array
    {
        $dateFilter = null !== $threshold ? " WHERE created_at >= {$threshold}" : '';

        // Spec 096 / C2 — when scam_type is provided, llm_usage rows are filtered by
        // conversation_id matching conversations of that scam_type. Rows with NULL
        // conversation_id (non-conv LLM calls) are excluded — they don't belong to
        // any scam_type.
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];
        $llmScamFilter = null !== $scamType
            ? ' conversation_id::text IN (SELECT conv_id::text FROM conversation'
            . ' WHERE scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND deleted_at IS NULL)'
            : '';

        // Compose llm_usage filter combining date + scam_type
        if ('' !== $llmScamFilter) {
            $dateFilter = null !== $threshold
                ? " WHERE created_at >= {$threshold} AND{$llmScamFilter}"
                : " WHERE{$llmScamFilter}";
        }

        $totalCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage{$dateFilter}",
            $params,
        );

        // Current month cost
        $currentMonthCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= DATE_TRUNC('month', NOW())"
            . ('' !== $llmScamFilter ? " AND{$llmScamFilter}" : ''),
            $params,
        );

        // Previous month cost
        $previousMonthCost = $this->fetchFloat(
            'SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage'
            . " WHERE created_at >= DATE_TRUNC('month', NOW() - INTERVAL '1 month')"
            . " AND created_at < DATE_TRUNC('month', NOW())"
            . ('' !== $llmScamFilter ? " AND{$llmScamFilter}" : ''),
            $params,
        );

        // Get total IOCs and total hours for cost-per calculations
        $headerExclude = $this->headerExcludeClause();
        $iocDateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';

        // Spec 096 / C2 — sub-query filter for indicator table when scam_type set
        $scamIdSubquery = null !== $scamType
            ? ' AND indicator_id IN (SELECT DISTINCT oi.indicator_id FROM observed_ioc oi'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';

        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$iocDateFilter}{$scamIdSubquery}",
            $params,
        );

        $convDateFilter = null !== $threshold ? " AND ts_last >= {$threshold}" : '';
        $convScamFilter = null !== $scamType ? ' AND scam_type_id = ' . $this->scamTypeIdLookupSubquery() : '';

        $totalHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0 FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL{$convDateFilter}{$convScamFilter}",
            $params,
        );

        $costPerIoc = $totalIocs > 0 ? round($totalCost / $totalIocs, 4) : 0.0;
        $costPerHour = $totalHours > 0 ? round($totalCost / $totalHours, 4) : 0.0;

        return [
            'total_cost_usd' => round($totalCost, 4),
            'current_month_usd' => round($currentMonthCost, 4),
            'previous_month_usd' => round($previousMonthCost, 4),
            'cost_per_ioc_usd' => $costPerIoc,
            'cost_per_hour_usd' => $costPerHour,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCampaigns(?string $scamType = null): array
    {
        // Spec 096 / C2 — when scam_type set, narrow campaigns to those whose
        // associated messages live in conversations of that scam_type.
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];
        $campaignScamFilter = null !== $scamType
            ? ' WHERE campaign_id IN (SELECT DISTINCT mc.campaign_id FROM message_campaign mc'
            . ' JOIN message m ON mc.msg_id::text = m.msg_id::text'
            . ' JOIN conversation c ON m.conv_id::text = c.conv_id::text'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';

        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,'
            . " COUNT(CASE WHEN status = 'promoted' THEN 1 END) AS promoted"
            . ' FROM campaign'
            . $campaignScamFilter,
            $params,
        );

        $row = \is_array($row) ? $row : [];

        // Distinct scam types in campaigns
        // When filter active: this is always 1 (the filtered type) or 0.
        if (null !== $scamType) {
            $scamTypeCount = $this->fetchInt(
                'SELECT COUNT(DISTINCT c.scam_type_id) FROM conversation c'
                . ' INNER JOIN message msg ON msg.conv_id = c.conv_id'
                . ' INNER JOIN message_campaign mc ON mc.msg_id::text = msg.msg_id::text'
                . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery(),
                $params,
            );
        } else {
            $scamTypeCount = $this->fetchInt(
                'SELECT COUNT(DISTINCT c.scam_type_id) FROM conversation c'
                . ' INNER JOIN message msg ON msg.conv_id = c.conv_id'
                . ' INNER JOIN message_campaign mc ON mc.msg_id::text = msg.msg_id::text',
            );
        }

        // Top 5 promoted campaigns — same scam_type filter applies via campaign_id subquery
        $topPromotedWhere = " WHERE c.status = 'promoted'";

        if (null !== $scamType) {
            $topPromotedWhere .= ' AND c.campaign_id IN (SELECT DISTINCT mc3.campaign_id FROM message_campaign mc3'
                . ' JOIN message m3 ON mc3.msg_id::text = m3.msg_id::text'
                . ' JOIN conversation cv3 ON m3.conv_id::text = cv3.conv_id::text'
                . ' WHERE cv3.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
                . ' AND m3.deleted_at IS NULL AND cv3.deleted_at IS NULL)';
        }

        $topRows = $this->connection->fetchAllAssociative(
            'SELECT c.campaign_id, c.status, c.severity, c.first_seen, c.tlp,'
            . ' COUNT(DISTINCT msg.conv_id) AS conv_count,'
            . ' COUNT(DISTINCT oi.indicator_id) AS ioc_count,'
            . ' (SELECT st2.code FROM message_campaign mc2'
            . ' JOIN message m2 ON mc2.msg_id::text = m2.msg_id::text'
            . ' JOIN conversation cv2 ON m2.conv_id::text = cv2.conv_id::text'
            . ' JOIN lkp_scam_type st2 ON cv2.scam_type_id = st2.scam_type_id'
            . ' WHERE mc2.campaign_id::text = c.campaign_id::text'
            . ' GROUP BY st2.code ORDER BY COUNT(*) DESC LIMIT 1'
            . ') AS dominant_scam_type'
            . ' FROM campaign c'
            . ' LEFT JOIN message_campaign mc ON c.campaign_id::text = mc.campaign_id::text'
            . ' LEFT JOIN message msg ON mc.msg_id::text = msg.msg_id::text'
            . ' LEFT JOIN observed_ioc oi ON oi.msg_id::text = msg.msg_id::text'
            . " WHERE c.status = 'promoted'"
            . ' GROUP BY c.campaign_id, c.status, c.severity, c.first_seen, c.tlp'
            . ' ORDER BY conv_count DESC'
            . ' LIMIT 5',
        );

        $topCampaigns = array_map(static fn (array $r): array => [
            'campaign_id' => self::rowStr($r, 'campaign_id'),
            'status' => self::rowStr($r, 'status'),
            'severity' => self::rowStr($r, 'severity'),
            'first_seen' => self::rowStr($r, 'first_seen'),
            'tlp' => self::rowStr($r, 'tlp'),
            'conv_count' => self::rowInt($r, 'conv_count'),
            'ioc_count' => self::rowInt($r, 'ioc_count'),
            'dominant_scam_type' => \is_string($r['dominant_scam_type'] ?? null) ? $r['dominant_scam_type'] : null,
        ], $topRows);

        return [
            'total' => self::rowInt($row, 'total'),
            'promoted' => self::rowInt($row, 'promoted'),
            'scam_type_count' => $scamTypeCount,
            'top_campaigns' => $topCampaigns,
        ];
    }

    /**
     * Compute trend deltas comparing the current period to the previous equivalent period.
     *
     * @return array{wasted_hours_delta_pct: float|null, novel_pct_delta: float|null, cost_per_ioc_delta_pct: float|null, campaigns_delta: int|null}|null
     */
    private function computeTrends(string $period, ?string $scamType = null): ?array
    {
        $daysMap = ['7d' => 7, '30d' => 30, '90d' => 90];

        if (!isset($daysMap[$period])) {
            return null;
        }

        $days = $daysMap[$period];
        $doubleDays = $days * 2;
        $prevStart = "NOW() - INTERVAL '{$doubleDays} days'";
        $prevEnd = "NOW() - INTERVAL '{$days} days'";
        $currStart = "NOW() - INTERVAL '{$days} days'";
        $headerExclude = $this->headerExcludeClause();

        // Spec 096 / C2 — scam_type filter fragments reused across all trend queries.
        $params = null !== $scamType ? ['scam_type' => $scamType] : [];
        $convScamFilter = null !== $scamType ? ' AND scam_type_id = ' . $this->scamTypeIdLookupSubquery() : '';
        $iocScamSubquery = null !== $scamType
            ? ' AND indicator_id IN (SELECT DISTINCT oi.indicator_id FROM observed_ioc oi'
            . ' JOIN message m ON oi.msg_id = m.msg_id'
            . ' JOIN conversation c ON m.conv_id = c.conv_id'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';
        $llmScamFilter = null !== $scamType
            ? ' AND conversation_id::text IN (SELECT conv_id::text FROM conversation'
            . ' WHERE scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND deleted_at IS NULL)'
            : '';
        $campaignScamFilter = null !== $scamType
            ? ' AND campaign_id IN (SELECT DISTINCT mc.campaign_id FROM message_campaign mc'
            . ' JOIN message m ON mc.msg_id::text = m.msg_id::text'
            . ' JOIN conversation c ON m.conv_id::text = c.conv_id::text'
            . ' WHERE c.scam_type_id = ' . $this->scamTypeIdLookupSubquery()
            . ' AND m.deleted_at IS NULL AND c.deleted_at IS NULL)'
            : '';

        // Current wasted hours (spec 107 — qualified convs only, must match
        // the headline filter so the delta % stays internally consistent).
        $currHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . ' AND turns_count >= 2'
            . " AND ts_last >= {$currStart}"
            . $convScamFilter,
            $params,
        );

        // Previous wasted hours (same filter, same reason).
        $prevHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . ' AND turns_count >= 2'
            . " AND ts_last >= {$prevStart} AND ts_last < {$prevEnd}"
            . $convScamFilter,
            $params,
        );

        // Current novel IOC percentage
        $currTotalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$currStart}{$iocScamSubquery}",
            $params,
        );
        $currNovelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$currStart}{$iocScamSubquery}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
            $params,
        );
        $currNovelPct = $currTotalIocs > 0 ? round($currNovelIocs * 100.0 / $currTotalIocs, 1) : 0.0;

        // Previous novel IOC percentage
        $prevTotalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$prevStart} AND created_at < {$prevEnd}{$iocScamSubquery}",
            $params,
        );
        $prevNovelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$prevStart} AND created_at < {$prevEnd}{$iocScamSubquery}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
            $params,
        );
        $prevNovelPct = $prevTotalIocs > 0 ? round($prevNovelIocs * 100.0 / $prevTotalIocs, 1) : 0.0;

        // Current cost
        $currCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= {$currStart}{$llmScamFilter}",
            $params,
        );

        // Previous cost
        $prevCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= {$prevStart} AND created_at < {$prevEnd}{$llmScamFilter}",
            $params,
        );

        // Cost per IOC
        $currCostPerIoc = $currTotalIocs > 0 ? $currCost / $currTotalIocs : 0.0;
        $prevCostPerIoc = $prevTotalIocs > 0 ? $prevCost / $prevTotalIocs : 0.0;

        // Current campaigns
        $currCampaigns = $this->fetchInt(
            "SELECT COUNT(*) FROM campaign WHERE created_at >= {$currStart}{$campaignScamFilter}",
            $params,
        );

        // Previous campaigns
        $prevCampaigns = $this->fetchInt(
            "SELECT COUNT(*) FROM campaign WHERE created_at >= {$prevStart} AND created_at < {$prevEnd}{$campaignScamFilter}",
            $params,
        );

        // Compute deltas
        $deltaHours = $prevHours > 0 ? round(($currHours - $prevHours) / $prevHours * 100, 1) : null;
        $deltaCostPerIoc = $prevCostPerIoc > 0 ? round(($currCostPerIoc - $prevCostPerIoc) / $prevCostPerIoc * 100, 1) : null;

        return [
            'wasted_hours_delta_pct' => $deltaHours,
            'novel_pct_delta' => round($currNovelPct - $prevNovelPct, 1),
            'cost_per_ioc_delta_pct' => $deltaCostPerIoc,
            'campaigns_delta' => $currCampaigns - $prevCampaigns,
        ];
    }

    private function periodToThreshold(string $period): ?string
    {
        return match ($period) {
            '7d' => "NOW() - INTERVAL '7 days'",
            '30d' => "NOW() - INTERVAL '30 days'",
            '90d' => "NOW() - INTERVAL '90 days'",
            default => null,
        };
    }

    private function headerExcludeClause(): string
    {
        $in = $this->inClause(self::HEADER_TYPES);

        return "type NOT IN ({$in})";
    }

    /**
     * @param list<string> $values
     */
    private function inClause(array $values): string
    {
        return implode(',', array_map(fn (string $v) => $this->connection->quote($v), $values));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowStr(array $row, string $key): string
    {
        $val = $row[$key] ?? '';

        return \is_string($val) ? $val : (\is_numeric($val) ? (string) $val : '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowInt(array $row, string $key): int
    {
        return \is_numeric($row[$key]) ? (int) $row[$key] : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowFloat(array $row, string $key): float
    {
        return \is_numeric($row[$key]) ? (float) $row[$key] : 0.0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchInt(string $sql, array $params = []): int
    {
        $result = $this->connection->fetchOne($sql, $params);

        return \is_numeric($result) ? (int) $result : 0;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetchFloat(string $sql, array $params = []): float
    {
        $result = $this->connection->fetchOne($sql, $params);

        return \is_numeric($result) ? (float) $result : 0.0;
    }
}
