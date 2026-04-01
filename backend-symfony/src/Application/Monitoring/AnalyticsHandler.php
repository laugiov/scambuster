<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

final class AnalyticsHandler
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * IOC extraction count per day.
     *
     * @return array{period_days: int, data: list<array<string, mixed>>}
     */
    public function getIocTimeline(int $days = 30): array
    {
        $days = min($days, 90);

        $sql = <<<SQL
            SELECT DATE(oi.ts_observed) as date, COUNT(*) as count
            FROM observed_ioc oi
            WHERE oi.ts_observed > NOW() - INTERVAL '{$days} days'
            GROUP BY DATE(oi.ts_observed)
            ORDER BY date ASC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        return [
            'period_days' => $days,
            'data' => $this->fillMissingDays($rows, $days, ['count' => 0]),
        ];
    }

    /**
     * Conversations opened and closed per day.
     *
     * @return array{period_days: int, data: list<array{date: string, opened: int, closed: int}>}
     */
    public function getConversationTimeline(int $days = 30): array
    {
        $days = min($days, 90);

        $sqlOpened = <<<SQL
            SELECT DATE(c.ts_first) as date, COUNT(*) as opened
            FROM conversation c
            WHERE c.ts_first > NOW() - INTERVAL '{$days} days'
              AND c.deleted_at IS NULL
            GROUP BY DATE(c.ts_first)
            ORDER BY date ASC
            SQL;

        $sqlClosed = <<<SQL
            SELECT DATE(c.updated_at) as date, COUNT(*) as closed
            FROM conversation c
            WHERE c.status = 'closed'
              AND c.updated_at > NOW() - INTERVAL '{$days} days'
              AND c.deleted_at IS NULL
            GROUP BY DATE(c.updated_at)
            ORDER BY date ASC
            SQL;

        $openedRows = $this->connection->fetchAllAssociative($sqlOpened);
        $closedRows = $this->connection->fetchAllAssociative($sqlClosed);

        $openedByDate = [];

        foreach ($openedRows as $row) {
            $openedByDate[self::rowStr($row, 'date')] = self::rowInt($row, 'opened');
        }

        $closedByDate = [];

        foreach ($closedRows as $row) {
            $closedByDate[self::rowStr($row, 'date')] = self::rowInt($row, 'closed');
        }

        $data = [];
        $startDate = new \DateTimeImmutable("-{$days} days");
        $endDate = new \DateTimeImmutable('today');

        for ($d = $startDate; $d <= $endDate; $d = $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');
            $data[] = [
                'date' => $dateStr,
                'opened' => $openedByDate[$dateStr] ?? 0,
                'closed' => $closedByDate[$dateStr] ?? 0,
            ];
        }

        return [
            'period_days' => $days,
            'data' => $data,
        ];
    }

    /**
     * IOC count by type.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function getIocDistribution(): array
    {
        $sql = <<<'SQL'
            SELECT
                oi.context_observation->>'type' as label,
                COUNT(*) as count
            FROM observed_ioc oi
            WHERE oi.context_observation->>'type' IS NOT NULL
            GROUP BY oi.context_observation->>'type'
            ORDER BY count DESC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $data = array_map(static fn (array $row) => [
            'label' => self::rowStr($row, 'label'),
            'count' => self::rowInt($row, 'count'),
        ], $rows);

        return ['data' => $data];
    }

    /**
     * Conversation count by scam type.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function getScamDistribution(): array
    {
        $sql = <<<'SQL'
            SELECT st.code as label, COUNT(*) as count
            FROM conversation c
            JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
            WHERE c.deleted_at IS NULL
            GROUP BY st.code
            ORDER BY count DESC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $data = array_map(static fn (array $row) => [
            'label' => self::rowStr($row, 'label'),
            'count' => self::rowInt($row, 'count'),
        ], $rows);

        return ['data' => $data];
    }

    /**
     * Daily LLM cost from pipeline traces.
     *
     * @return array{period_days: int, data: list<array{date: string, cost_usd: float}>}
     */
    public function getCostTimeline(int $days = 30): array
    {
        $days = min($days, 90);

        $sql = <<<SQL
            SELECT
                DATE(m.ts_msg) as date,
                COALESCE(SUM((m.headers->'pipeline_trace'->>'total_cost')::numeric), 0) as cost_usd
            FROM message m
            WHERE m.direction = 2
              AND m.ts_msg > NOW() - INTERVAL '{$days} days'
              AND jsonb_exists(m.headers::jsonb, 'pipeline_trace')
            GROUP BY DATE(m.ts_msg)
            ORDER BY date ASC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $data = [];
        $startDate = new \DateTimeImmutable("-{$days} days");
        $endDate = new \DateTimeImmutable('today');

        $costByDate = [];

        foreach ($rows as $row) {
            $costByDate[self::rowStr($row, 'date')] = round(self::rowFloat($row, 'cost_usd'), 6);
        }

        for ($d = $startDate; $d <= $endDate; $d = $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');
            $data[] = [
                'date' => $dateStr,
                'cost_usd' => $costByDate[$dateStr] ?? 0.0,
            ];
        }

        return [
            'period_days' => $days,
            'data' => $data,
        ];
    }

    /**
     * Pipeline reply outcomes per day (approved/fallback/rejected).
     *
     * @return array{period_days: int, data: list<array{date: string, approved: int, fallback: int, rejected: int}>}
     */
    public function getPipelineTimeline(int $days = 30): array
    {
        $days = min($days, 90);

        $sql = <<<SQL
            SELECT
                DATE(m.ts_msg) as date,
                COUNT(*) FILTER (WHERE (m.headers->'pipeline_trace'->>'approved')::boolean = true) as approved,
                COUNT(*) FILTER (WHERE (m.headers->'pipeline_trace'->>'fallback_used')::boolean = true) as fallback,
                COUNT(*) FILTER (WHERE (m.headers->'pipeline_trace'->>'approved')::boolean = false
                    AND (m.headers->'pipeline_trace'->>'fallback_used')::boolean = false) as rejected
            FROM message m
            WHERE m.direction = 2
              AND m.ts_msg > NOW() - INTERVAL '{$days} days'
              AND jsonb_exists(m.headers::jsonb, 'pipeline_trace')
            GROUP BY DATE(m.ts_msg)
            ORDER BY date ASC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $byDate = [];

        foreach ($rows as $row) {
            $byDate[self::rowStr($row, 'date')] = [
                'approved' => self::rowInt($row, 'approved'),
                'fallback' => self::rowInt($row, 'fallback'),
                'rejected' => self::rowInt($row, 'rejected'),
            ];
        }

        $data = [];
        $startDate = new \DateTimeImmutable("-{$days} days");
        $endDate = new \DateTimeImmutable('today');

        for ($d = $startDate; $d <= $endDate; $d = $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');
            $data[] = [
                'date' => $dateStr,
                'approved' => $byDate[$dateStr]['approved'] ?? 0,
                'fallback' => $byDate[$dateStr]['fallback'] ?? 0,
                'rejected' => $byDate[$dateStr]['rejected'] ?? 0,
            ];
        }

        return [
            'period_days' => $days,
            'data' => $data,
        ];
    }

    /**
     * Recent activity feed (mixed event types).
     *
     * @return array{events: list<array{event_type: string, ref_id: string, ts: string}>}
     */
    public function getActivityFeed(int $limit = 10): array
    {
        $limit = min($limit, 50);

        $sql = <<<SQL
            (
                SELECT 'conversation_opened' as event_type, c.conv_id::text as ref_id, c.ts_first as ts
                FROM conversation c
                WHERE c.deleted_at IS NULL
                ORDER BY c.ts_first DESC
                LIMIT {$limit}
            )
            UNION ALL
            (
                SELECT 'reply_sent' as event_type, m.msg_id::text as ref_id, m.ts_msg as ts
                FROM message m
                WHERE m.direction = 2
                ORDER BY m.ts_msg DESC
                LIMIT {$limit}
            )
            UNION ALL
            (
                SELECT 'ioc_extracted' as event_type, oi.obs_id::text as ref_id, oi.ts_observed as ts
                FROM observed_ioc oi
                ORDER BY oi.ts_observed DESC
                LIMIT {$limit}
            )
            ORDER BY ts DESC
            LIMIT {$limit}
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $events = array_map(static fn (array $row) => [
            'event_type' => self::rowStr($row, 'event_type'),
            'ref_id' => self::rowStr($row, 'ref_id'),
            'ts' => self::rowStr($row, 'ts'),
        ], $rows);

        return ['events' => $events];
    }

    /**
     * Weekly trends: current week vs previous week.
     *
     * @return array{trends: list<array{metric: string, current: float|int, previous: float|int, delta_pct: float|null}>}
     */
    public function getWeeklyTrends(): array
    {
        $thisWeekStart = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');
        $lastWeekStart = (new \DateTimeImmutable('monday last week'))->format('Y-m-d');

        $trends = [];

        // Conversations
        $currentConvs = $this->fetchInt(
            'SELECT COUNT(*) FROM conversation WHERE ts_first >= :start AND deleted_at IS NULL',
            ['start' => $thisWeekStart],
        );
        $previousConvs = $this->fetchInt(
            'SELECT COUNT(*) FROM conversation WHERE ts_first >= :start AND ts_first < :end AND deleted_at IS NULL',
            ['start' => $lastWeekStart, 'end' => $thisWeekStart],
        );
        $trends[] = $this->buildTrend('conversations', $currentConvs, $previousConvs);

        // IOCs
        $currentIocs = $this->fetchInt(
            'SELECT COUNT(*) FROM observed_ioc WHERE ts_observed >= :start',
            ['start' => $thisWeekStart],
        );
        $previousIocs = $this->fetchInt(
            'SELECT COUNT(*) FROM observed_ioc WHERE ts_observed >= :start AND ts_observed < :end',
            ['start' => $lastWeekStart, 'end' => $thisWeekStart],
        );
        $trends[] = $this->buildTrend('iocs', $currentIocs, $previousIocs);

        // Replies
        $currentReplies = $this->fetchInt(
            'SELECT COUNT(*) FROM message WHERE direction = 2 AND ts_msg >= :start',
            ['start' => $thisWeekStart],
        );
        $previousReplies = $this->fetchInt(
            'SELECT COUNT(*) FROM message WHERE direction = 2 AND ts_msg >= :start AND ts_msg < :end',
            ['start' => $lastWeekStart, 'end' => $thisWeekStart],
        );
        $trends[] = $this->buildTrend('replies', $currentReplies, $previousReplies);

        // Cost
        $currentCost = $this->fetchFloat(
            "SELECT COALESCE(SUM((headers->'pipeline_trace'->>'total_cost')::numeric), 0) FROM message WHERE direction = 2 AND ts_msg >= :start AND jsonb_exists(headers::jsonb, 'pipeline_trace')",
            ['start' => $thisWeekStart],
        );
        $previousCost = $this->fetchFloat(
            "SELECT COALESCE(SUM((headers->'pipeline_trace'->>'total_cost')::numeric), 0) FROM message WHERE direction = 2 AND ts_msg >= :start AND ts_msg < :end AND jsonb_exists(headers::jsonb, 'pipeline_trace')",
            ['start' => $lastWeekStart, 'end' => $thisWeekStart],
        );
        $trends[] = $this->buildTrend('cost', round($currentCost, 4), round($previousCost, 4));

        return ['trends' => $trends];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int|float>   $defaults
     *
     * @return list<array<string, mixed>>
     */
    private function fillMissingDays(array $rows, int $days, array $defaults): array
    {
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[self::rowStr($row, 'date')] = $row;
        }

        $data = [];
        $startDate = new \DateTimeImmutable("-{$days} days");
        $endDate = new \DateTimeImmutable('today');

        for ($d = $startDate; $d <= $endDate; $d = $d->modify('+1 day')) {
            $dateStr = $d->format('Y-m-d');

            if (isset($byDate[$dateStr])) {
                $entry = ['date' => $dateStr];

                foreach ($defaults as $key => $default) {
                    $entry[$key] = isset($byDate[$dateStr][$key]) ? self::rowInt($byDate[$dateStr], $key) : $default;
                }
                $data[] = $entry;
            } else {
                $data[] = array_merge(['date' => $dateStr], $defaults);
            }
        }

        return $data;
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

    /**
     * @return array{metric: string, current: float|int, previous: float|int, delta_pct: float|null}
     */
    private function buildTrend(string $metric, float|int $current, float|int $previous): array
    {
        $deltaPct = null;

        if ($previous > 0) {
            $deltaPct = round(($current - $previous) / $previous * 100, 1);
        }

        return [
            'metric' => $metric,
            'current' => $current,
            'previous' => $previous,
            'delta_pct' => $deltaPct,
        ];
    }
}
