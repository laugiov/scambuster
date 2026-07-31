<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

/**
 * Aggregates LLM cost data from the llm_usage table.
 *
 * Provides current month costs, per-purpose breakdown,
 * and daily trend for the cost monitoring endpoint.
 */
final readonly class LlmCostHandler
{
    public function __construct(
        private Connection $connection,
        private float $monthlyLimitUsd
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getCostReport(): array
    {
        $firstOfMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');

        $monthData = $this->connection->fetchAssociative(
            'SELECT
                COALESCE(SUM(estimated_cost_usd::numeric), 0) as total_usd,
                COUNT(*) as calls_count,
                COALESCE(SUM(prompt_tokens), 0) as total_prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) as total_completion_tokens
            FROM llm_usage
            WHERE created_at >= :first_of_month',
            ['first_of_month' => $firstOfMonth]
        );

        $totalUsd = round($this->toFloat($monthData['total_usd'] ?? 0), 6);
        $callsCount = $this->toInt($monthData['calls_count'] ?? 0);

        $purposeRows = $this->connection->fetchAllAssociative(
            'SELECT
                purpose,
                COALESCE(SUM(estimated_cost_usd::numeric), 0) as cost_usd,
                COUNT(*) as calls
            FROM llm_usage
            WHERE created_at >= :first_of_month
            GROUP BY purpose
            ORDER BY cost_usd DESC',
            ['first_of_month' => $firstOfMonth]
        );

        $perPurpose = [];

        foreach ($purposeRows as $row) {
            /** @var string $purpose */
            $purpose = $row['purpose'];
            $perPurpose[$purpose] = [
                'cost_usd' => round($this->toFloat($row['cost_usd'] ?? 0), 6),
                'calls' => $this->toInt($row['calls'] ?? 0),
            ];
        }

        $dailyRows = $this->connection->fetchAllAssociative(
            'SELECT
                DATE(created_at) as date,
                COALESCE(SUM(estimated_cost_usd::numeric), 0) as cost_usd,
                COUNT(*) as calls
            FROM llm_usage
            WHERE created_at >= :seven_days_ago
            GROUP BY DATE(created_at)
            ORDER BY date DESC',
            ['seven_days_ago' => (new \DateTimeImmutable('-7 days'))->format('Y-m-d 00:00:00')]
        );

        $dailyTrend = [];

        foreach ($dailyRows as $row) {
            $dailyTrend[] = [
                'date' => is_string($row['date'] ?? null) ? $row['date'] : '',
                'cost_usd' => round($this->toFloat($row['cost_usd'] ?? 0), 6),
                'calls' => $this->toInt($row['calls'] ?? 0),
            ];
        }

        return [
            'current_month' => [
                'total_usd' => $totalUsd,
                'limit_usd' => $this->monthlyLimitUsd,
                'pct_used' => $this->monthlyLimitUsd > 0
                    ? round(($totalUsd / $this->monthlyLimitUsd) * 100, 1)
                    : 0,
                'calls_count' => $callsCount,
                'total_prompt_tokens' => $this->toInt($monthData['total_prompt_tokens'] ?? 0),
                'total_completion_tokens' => $this->toInt($monthData['total_completion_tokens'] ?? 0),
            ],
            'per_purpose' => (object) $perPurpose,
            'daily_trend' => $dailyTrend,
            'limit_exceeded' => $this->monthlyLimitUsd > 0 && $totalUsd >= $this->monthlyLimitUsd,
        ];
    }

    public function isLimitExceeded(): bool
    {
        return $this->isThresholdReached(1.0);
    }

    /**
     * Soft warning threshold check.
     *
     * Returns true when the current month spend is greater than or equal
     * to `$thresholdPct * $monthlyLimitUsd`. Returns false when the limit
     * is zero or negative (the cap is disabled).
     *
     * The default threshold of 0.8 (80%) is the soft-warning umbrella
     * decision. Pass a
     * higher value to query the hard cap (e.g., `isThresholdReached(1.0)`
     * is equivalent to `isLimitExceeded()`).
     */
    public function isThresholdReached(float $thresholdPct = 0.8): bool
    {
        if ($this->monthlyLimitUsd <= 0) {
            return false;
        }

        return $this->getCurrentMonthUsdSpent() >= ($this->monthlyLimitUsd * $thresholdPct);
    }

    /**
     * Current month-to-date spend in USD.
     *
     * Used by `LlmBudgetExceededException` and the soft warning notifier.
     */
    public function getCurrentMonthUsdSpent(): float
    {
        $firstOfMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');

        $result = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(estimated_cost_usd::numeric), 0)
            FROM llm_usage
            WHERE created_at >= :first_of_month',
            ['first_of_month' => $firstOfMonth]
        );

        return $this->toFloat($result);
    }

    /**
     * Configured monthly budget cap in USD.
     */
    public function getMonthlyLimitUsd(): float
    {
        return $this->monthlyLimitUsd;
    }

    private function toFloat(mixed $value): float
    {
        return (float) (is_numeric($value) ? $value : 0);
    }

    private function toInt(mixed $value): int
    {
        return (int) (is_numeric($value) ? $value : 0);
    }
}
