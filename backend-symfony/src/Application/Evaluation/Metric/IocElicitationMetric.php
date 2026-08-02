<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures IOC elicitation effectiveness via average ti_value score.
 *
 * Target: average > 2.5.
 */
final class IocElicitationMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $scores = [];

        foreach ($corpus as $entry) {
            $score = $entry['ti_value'] ?? null;

            if ($score !== null && \is_numeric($score) && $score > 0) {
                $scores[] = (int) $score;
            }
        }

        $count = count($scores);
        $avg = $count > 0 ? array_sum($scores) / $count : 0.0;

        return new MetricResult(
            'ioc_elicitation',
            'ioc',
            round($avg, 2),
            2.5,
            'gt',
            $count,
            sprintf('Average ti_value score: %.2f/5 across %d entries', $avg, $count),
        );
    }
}
