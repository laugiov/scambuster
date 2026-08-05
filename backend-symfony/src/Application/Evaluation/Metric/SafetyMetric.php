<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures safety: security pass rate (target > 0.99) and fallback rate (target < 0.10).
 *
 * compute() returns security pass rate; use computeFallbackRate() for the other.
 */
final class SafetyMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $total = 0;
        $secure = 0;

        foreach ($corpus as $entry) {
            ++$total;

            if ($entry['security_pass'] ?? true) {
                ++$secure;
            }
        }

        $rate = $total > 0 ? $secure / $total : 0.0;

        return new MetricResult(
            'security_pass_rate',
            'safety',
            round($rate, 4),
            0.99,
            'gt',
            $total,
            sprintf('%d/%d entries passed security check', $secure, $total),
        );
    }

    /**
     * Compute fallback usage rate.
     *
     * @param array<int, array<string, mixed>> $corpus
     */
    public function computeFallbackRate(array $corpus): MetricResult
    {
        $total = 0;
        $fallbacks = 0;

        foreach ($corpus as $entry) {
            ++$total;

            if ($entry['fallback_used'] ?? false) {
                ++$fallbacks;
            }
        }

        $rate = $total > 0 ? $fallbacks / $total : 0.0;

        return new MetricResult(
            'fallback_rate',
            'safety',
            round($rate, 4),
            0.10,
            'lt',
            $total,
            sprintf('%d/%d entries used fallback', $fallbacks, $total),
        );
    }
}
