<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Queries prompt injection detection results for the monitoring dashboard.
 */
final class InjectionMonitoringHandler
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(int $days = 7): array
    {
        $days = min($days, 30);

        // Total messages and analyzed count
        $totals = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) as total_inbound,
                COUNT(injection_analysis) as analyzed,
                COUNT(*) FILTER (WHERE injection_analysis IS NOT NULL AND (injection_analysis->>'risk_score')::numeric > 50) as high_risk,
                COUNT(*) FILTER (WHERE injection_analysis IS NOT NULL AND (injection_analysis->>'risk_score')::numeric BETWEEN 20 AND 50) as medium_risk,
                COUNT(*) FILTER (WHERE injection_analysis IS NOT NULL AND (injection_analysis->>'risk_score')::numeric < 20) as low_risk
            FROM message
            WHERE direction = 3 AND ts_msg > NOW() - INTERVAL '{$days} days'
        ");

        // Recent high-risk detections
        $recentAlerts = $this->connection->fetchAllAssociative("
            SELECT
                m.msg_id,
                m.conv_id,
                m.ts_msg,
                (m.injection_analysis->>'risk_score')::numeric as risk_score,
                m.injection_analysis->>'risk_level' as risk_level,
                m.injection_analysis->'patterns' as patterns
            FROM message m
            WHERE m.direction = 3
              AND m.injection_analysis IS NOT NULL
              AND (m.injection_analysis->>'risk_score')::numeric > 30
              AND m.ts_msg > NOW() - INTERVAL '{$days} days'
            ORDER BY (m.injection_analysis->>'risk_score')::numeric DESC
            LIMIT 20
        ");

        /** @var array{total_inbound: int|string, analyzed: int|string, high_risk: int|string, medium_risk: int|string, low_risk: int|string} $totals */
        $totalInbound = (int) $totals['total_inbound'];
        $analyzed = (int) $totals['analyzed'];

        return [
            'period_days' => $days,
            'total_inbound' => $totalInbound,
            'analyzed' => $analyzed,
            'coverage_pct' => $totalInbound > 0 ? round($analyzed / $totalInbound * 100, 1) : 0,
            'high_risk' => (int) $totals['high_risk'],
            'medium_risk' => (int) $totals['medium_risk'],
            'low_risk' => (int) $totals['low_risk'],
            'recent_alerts' => $recentAlerts,
        ];
    }
}
