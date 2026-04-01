<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Domain\LLM\PipelineTrace;
use Doctrine\DBAL\Connection;

/**
 * Queries pipeline traces from outbound message headers.
 *
 * All traces are stored in message.headers JSONB column as 'pipeline_trace'.
 * This handler provides read-only access with volume-safe pagination and filtering.
 */
final class PipelineTraceHandler
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Get recent pipeline traces with filtering and pagination.
     *
     * @return array{traces: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function getRecentTraces(
        int $days = 7,
        int $limit = 50,
        int $offset = 0,
        ?string $persona = null,
        ?string $scamType = null,
    ): array {
        $days = min($days, 30);
        $limit = min($limit, 100);

        $where = "m.direction = 2 AND m.ts_msg > NOW() - INTERVAL '{$days} days' AND jsonb_exists(m.headers::jsonb, 'pipeline_trace')";
        $params = [];

        if ($persona !== null) {
            $where .= " AND m.headers->>'llm_persona' = :persona";
            $params['persona'] = $persona;
        }

        if ($scamType !== null) {
            $where .= " AND (m.headers->'pipeline_trace'->>'scam_type') = :scam_type";
            $params['scam_type'] = $scamType;
        }

        $countSql = "SELECT COUNT(*) FROM message m WHERE {$where}";
        $countResult = $this->connection->fetchOne($countSql, $params);
        $total = \is_numeric($countResult) ? (int) $countResult : 0;

        $sql = <<<SQL
            SELECT
                m.msg_id,
                m.conv_id,
                m.ts_msg,
                m.headers->'pipeline_trace' as pipeline_trace
            FROM message m
            WHERE {$where}
            ORDER BY m.ts_msg DESC
            LIMIT {$limit} OFFSET {$offset}
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $traces = [];

        foreach ($rows as $row) {
            /** @var string $traceJson */
            $traceJson = $row['pipeline_trace'] ?? '{}';
            $traceData = json_decode($traceJson, true);

            if (!\is_array($traceData)) {
                continue;
            }

            $trace = PipelineTrace::fromArray($traceData);
            $summary = $trace->toSummary();
            $summary['msg_id'] = $row['msg_id'];
            $summary['created_at'] = $row['ts_msg'];
            $traces[] = $summary;
        }

        return [
            'traces' => $traces,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get the full trace for a specific message.
     *
     * @return array<string, mixed>|null
     */
    public function getTraceByMessageId(string $msgId): ?array
    {
        $sql = <<<'SQL'
            SELECT m.headers->'pipeline_trace' as pipeline_trace
            FROM message m
            WHERE m.msg_id = :msgId AND m.direction = 2
            SQL;

        $result = $this->connection->fetchOne($sql, ['msgId' => $msgId]);

        if ($result === false || $result === null) {
            return null;
        }

        $data = json_decode(\is_string($result) ? $result : '', true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Get aggregated health metrics for a time window.
     *
     * @return array<string, mixed>
     */
    public function getHealthMetrics(int $hours = 24): array
    {
        $hours = min($hours, 168);

        $sql = <<<SQL
            SELECT m.headers->'pipeline_trace' as pipeline_trace
            FROM message m
            WHERE m.direction = 2
              AND m.ts_msg > NOW() - INTERVAL '{$hours} hours'
              AND jsonb_exists(m.headers::jsonb, 'pipeline_trace')
            ORDER BY m.ts_msg DESC
            SQL;

        $rows = $this->connection->fetchAllAssociative($sql);

        $componentStats = [];
        $totalDuration = 0.0;
        $totalCost = 0.0;
        $approvedCount = 0;
        $fallbackCount = 0;
        $totalReplies = 0;

        foreach ($rows as $row) {
            /** @var string $traceJson */
            $traceJson = $row['pipeline_trace'] ?? '{}';
            $traceData = json_decode($traceJson, true);

            if (!\is_array($traceData)) {
                continue;
            }

            $trace = PipelineTrace::fromArray($traceData);
            ++$totalReplies;
            $totalDuration += $trace->getTotalDurationMs();
            $totalCost += $trace->totalCost;

            if ($trace->approved) {
                ++$approvedCount;
            }

            if ($trace->fallbackUsed) {
                ++$fallbackCount;
            }

            foreach ($trace->getComponents() as $component) {
                $name = $component->name;

                if (!isset($componentStats[$name])) {
                    $componentStats[$name] = ['ran' => 0, 'skipped' => 0, 'error' => 0, 'total_duration' => 0.0, 'count' => 0];
                }

                ++$componentStats[$name]['count'];
                $componentStats[$name][$component->status] = ($componentStats[$name][$component->status] ?? 0) + 1;
                $componentStats[$name]['total_duration'] += $component->durationMs;
            }
        }

        $components = [];
        $alerts = [];

        foreach ($componentStats as $name => $stats) {
            $total = $stats['count'];
            $successRate = $total > 0 ? round($stats['ran'] / $total, 3) : 0.0;
            $skipRate = $total > 0 ? round(($stats['skipped'] ?? 0) / $total, 3) : 0.0;
            $errorRate = $total > 0 ? round(($stats['error'] ?? 0) / $total, 3) : 0.0;
            $avgDuration = $total > 0 ? round($stats['total_duration'] / $total, 1) : 0.0;

            $components[$name] = [
                'success_rate' => $successRate,
                'skip_rate' => $skipRate,
                'error_rate' => $errorRate,
                'avg_duration_ms' => $avgDuration,
            ];

            if ($successRate < 0.95 && $total >= 5) {
                $alerts[] = "{$name}: " . round((1 - $successRate) * 100) . '% failure/skip rate';
            }
        }

        // Cost comparison: today vs yesterday
        $costToday = $this->getCostForDate('today');
        $costYesterday = $this->getCostForDate('yesterday');

        return [
            'period_hours' => $hours,
            'total_replies' => $totalReplies,
            'avg_duration_ms' => $totalReplies > 0 ? round($totalDuration / $totalReplies, 1) : 0,
            'avg_cost' => $totalReplies > 0 ? round($totalCost / $totalReplies, 6) : 0,
            'approval_rate' => $totalReplies > 0 ? round($approvedCount / $totalReplies, 3) : 0,
            'fallback_rate' => $totalReplies > 0 ? round($fallbackCount / $totalReplies, 3) : 0,
            'components' => $components,
            'alerts' => $alerts,
            'cost_today' => $costToday,
            'cost_yesterday' => $costYesterday,
        ];
    }

    private function getCostForDate(string $day): float
    {
        $dateExpr = $day === 'today' ? 'CURRENT_DATE' : "CURRENT_DATE - INTERVAL '1 day'";

        $sql = <<<SQL
            SELECT COALESCE(SUM((m.headers->'pipeline_trace'->>'total_cost')::numeric), 0)
            FROM message m
            WHERE m.direction = 2
              AND m.ts_msg::date = {$dateExpr}
              AND jsonb_exists(m.headers::jsonb, 'pipeline_trace')
            SQL;

        $result = $this->connection->fetchOne($sql);

        return \is_numeric($result) ? round((float) $result, 4) : 0.0;
    }
}
