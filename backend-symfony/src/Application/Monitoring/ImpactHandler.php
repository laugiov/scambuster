<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

final class ImpactHandler
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
        private readonly Connection $connection,
    ) {
    }

    /**
     * Full impact summary with wasted_time, ioc_value, cost_efficiency, campaigns.
     *
     * @return array{wasted_time: array<string, mixed>, ioc_value: array<string, mixed>, cost_efficiency: array<string, mixed>, campaigns: array<string, mixed>}
     */
    public function getSummary(string $period = 'all'): array
    {
        $threshold = $this->periodToThreshold($period);

        return [
            'wasted_time' => $this->getWastedTime($threshold),
            'ioc_value' => $this->getIocValue($threshold),
            'cost_efficiency' => $this->getCostEfficiency($threshold),
            'campaigns' => $this->getCampaigns(),
            'trends' => $this->computeTrends($period),
        ];
    }

    /**
     * IOC uniqueness analysis with summary, by_type breakdown, and daily_trend.
     *
     * @return array{summary: array<string, mixed>, by_type: list<array<string, mixed>>, daily_trend: list<array<string, mixed>>}
     */
    public function getIocUniqueness(string $period = '30d', ?string $iocType = null): array
    {
        $threshold = $this->periodToThreshold($period);
        $headerExclude = $this->headerExcludeClause();
        $typeFilter = '';

        if (null !== $iocType && '' !== $iocType) {
            /** @var string $quoted */
            $quoted = $this->connection->quote($iocType);
            $typeFilter = ' AND type = ' . $quoted;
        }

        $dateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';

        // Summary
        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}",
        );

        $novelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
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
            . " FROM indicator WHERE {$headerExclude}{$typeFilter}{$dateFilter}"
            . ' GROUP BY type ORDER BY total DESC',
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

        // Daily trend (last 30 days)
        $trendRows = $this->connection->fetchAllAssociative(
            'SELECT DATE(created_at) as date, COUNT(*) as total,'
            . " COUNT(*) FILTER (WHERE enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal')) as novel"
            . " FROM indicator WHERE {$headerExclude}{$typeFilter}"
            . " AND created_at > NOW() - INTERVAL '30 days'"
            . ' GROUP BY DATE(created_at) ORDER BY date ASC',
        );

        $dailyTrend = array_map(static fn (array $row) => [
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
    private function getWastedTime(?string $threshold): array
    {
        $dateFilter = null !== $threshold ? " AND ts_last >= {$threshold}" : '';

        $row = $this->connection->fetchAssociative(
            'SELECT'
            . ' COALESCE(SUM(engagement_duration_sec), 0) / 3600.0 AS total_hours,'
            . ' COUNT(*) AS total_conversations,'
            . ' COALESCE(AVG(NULLIF(engagement_duration_sec, 0)), 0) / 3600.0 AS avg_hours,'
            . ' COALESCE(MAX(engagement_duration_sec), 0) / 3600.0 AS max_hours'
            . ' FROM conversation'
            . " WHERE status IN ('closed', 'open', 'abandoned')"
            . ' AND deleted_at IS NULL'
            . $dateFilter,
        );

        $row = \is_array($row) ? $row : [];

        // Scam type of the longest conversation
        $longestScamType = $this->connection->fetchOne(
            'SELECT st.code FROM conversation c'
            . ' JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id'
            . ' WHERE c.engagement_duration_sec = (SELECT MAX(engagement_duration_sec) FROM conversation WHERE deleted_at IS NULL)'
            . ' LIMIT 1',
        );

        // Weekly trend (last 12 weeks)
        $trendRows = $this->connection->fetchAllAssociative(
            "SELECT DATE_TRUNC('week', ts_last)::date AS week,"
            . ' SUM(engagement_duration_sec) / 3600.0 AS hours'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . " AND ts_last > NOW() - INTERVAL '12 weeks'"
            . " GROUP BY DATE_TRUNC('week', ts_last)"
            . ' ORDER BY week ASC',
        );

        $weeklyTrend = array_map(static fn (array $r) => [
            'week' => self::rowStr($r, 'week'),
            'hours' => round(self::rowFloat($r, 'hours'), 2),
        ], $trendRows);

        return [
            'total_hours' => round(self::rowFloat($row, 'total_hours'), 2),
            'total_conversations' => self::rowInt($row, 'total_conversations'),
            'avg_hours' => round(self::rowFloat($row, 'avg_hours'), 2),
            'max_hours' => round(self::rowFloat($row, 'max_hours'), 2),
            'longest_scam_type' => \is_string($longestScamType) ? $longestScamType : null,
            'weekly_trend' => $weeklyTrend,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getIocValue(?string $threshold): array
    {
        $headerExclude = $this->headerExcludeClause();
        $dateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';
        $obsDateFilter = null !== $threshold ? " AND ts_observed >= {$threshold}" : '';

        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$dateFilter}",
        );

        $novelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$dateFilter}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
        );

        $financialIn = $this->inClause(self::FINANCIAL_TYPES);
        $financialIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE type IN ({$financialIn}){$dateFilter}",
        );

        $highConfidence = $this->fetchInt(
            "SELECT COUNT(*) FROM observed_ioc WHERE confidence_score >= 0.9{$obsDateFilter}",
        );

        // By type (top 10)
        $byTypeRows = $this->connection->fetchAllAssociative(
            "SELECT type, COUNT(*) as count FROM indicator WHERE {$headerExclude}{$dateFilter}"
            . ' GROUP BY type ORDER BY count DESC LIMIT 10',
        );

        $byType = array_map(static fn (array $r) => [
            'type' => self::rowStr($r, 'type'),
            'count' => self::rowInt($r, 'count'),
        ], $byTypeRows);

        $novelPct = $totalIocs > 0 ? round($novelIocs * 100.0 / $totalIocs, 1) : 0.0;

        return [
            'total_iocs' => $totalIocs,
            'novel_iocs' => $novelIocs,
            'novel_pct' => $novelPct,
            'financial_iocs' => $financialIocs,
            'high_confidence' => $highConfidence,
            'by_type' => $byType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCostEfficiency(?string $threshold): array
    {
        $dateFilter = null !== $threshold ? " WHERE created_at >= {$threshold}" : '';

        $totalCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage{$dateFilter}",
        );

        // Current month cost
        $currentMonthCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= DATE_TRUNC('month', NOW())",
        );

        // Previous month cost
        $previousMonthCost = $this->fetchFloat(
            'SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage'
            . " WHERE created_at >= DATE_TRUNC('month', NOW() - INTERVAL '1 month')"
            . " AND created_at < DATE_TRUNC('month', NOW())",
        );

        // Get total IOCs and total hours for cost-per calculations
        $headerExclude = $this->headerExcludeClause();
        $iocDateFilter = null !== $threshold ? " AND created_at >= {$threshold}" : '';

        $totalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude}{$iocDateFilter}",
        );

        $convDateFilter = null !== $threshold ? " AND ts_last >= {$threshold}" : '';

        $totalHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0 FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL{$convDateFilter}",
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
    private function getCampaigns(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,'
            . " COUNT(CASE WHEN status = 'promoted' THEN 1 END) AS promoted"
            . ' FROM campaign',
        );

        $row = \is_array($row) ? $row : [];

        // Distinct scam types in campaigns
        $scamTypeCount = $this->fetchInt(
            'SELECT COUNT(DISTINCT c.scam_type_id) FROM conversation c'
            . ' INNER JOIN message msg ON msg.conv_id = c.conv_id'
            . ' INNER JOIN message_campaign mc ON mc.msg_id::text = msg.msg_id::text',
        );

        // Top 5 promoted campaigns
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

        $topCampaigns = array_map(static fn (array $r) => [
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
    private function computeTrends(string $period): ?array
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

        // Current wasted hours
        $currHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . " AND ts_last >= {$currStart}",
        );

        // Previous wasted hours
        $prevHours = $this->fetchFloat(
            'SELECT COALESCE(SUM(engagement_duration_sec), 0) / 3600.0'
            . ' FROM conversation'
            . " WHERE status IN ('closed','open','abandoned') AND deleted_at IS NULL"
            . " AND ts_last >= {$prevStart} AND ts_last < {$prevEnd}",
        );

        // Current novel IOC percentage
        $currTotalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$currStart}",
        );
        $currNovelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$currStart}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
        );
        $currNovelPct = $currTotalIocs > 0 ? round($currNovelIocs * 100.0 / $currTotalIocs, 1) : 0.0;

        // Previous novel IOC percentage
        $prevTotalIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$prevStart} AND created_at < {$prevEnd}",
        );
        $prevNovelIocs = $this->fetchInt(
            "SELECT COUNT(*) FROM indicator WHERE {$headerExclude} AND created_at >= {$prevStart} AND created_at < {$prevEnd}"
            . " AND (enrichment IS NULL OR enrichment::text = '{}' OR enrichment::text = 'null'"
            . " OR (enrichment::jsonb -> 'virustotal' ->> 'malicious')::int < 3"
            . " OR NOT jsonb_exists(enrichment::jsonb, 'virustotal'))",
        );
        $prevNovelPct = $prevTotalIocs > 0 ? round($prevNovelIocs * 100.0 / $prevTotalIocs, 1) : 0.0;

        // Current cost
        $currCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= {$currStart}",
        );

        // Previous cost
        $prevCost = $this->fetchFloat(
            "SELECT COALESCE(SUM(estimated_cost_usd), 0) FROM llm_usage WHERE created_at >= {$prevStart} AND created_at < {$prevEnd}",
        );

        // Cost per IOC
        $currCostPerIoc = $currTotalIocs > 0 ? $currCost / $currTotalIocs : 0.0;
        $prevCostPerIoc = $prevTotalIocs > 0 ? $prevCost / $prevTotalIocs : 0.0;

        // Current campaigns
        $currCampaigns = $this->fetchInt(
            "SELECT COUNT(*) FROM campaign WHERE created_at >= {$currStart}",
        );

        // Previous campaigns
        $prevCampaigns = $this->fetchInt(
            "SELECT COUNT(*) FROM campaign WHERE created_at >= {$prevStart} AND created_at < {$prevEnd}",
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
