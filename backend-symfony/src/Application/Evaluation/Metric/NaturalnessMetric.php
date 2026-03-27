<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures naturalness via first-attempt approval rate and average naturalness score.
 *
 * Returns two results: first-attempt rate (target > 0.60) and avg naturalness (target > 3.0).
 * The compute() method returns the first-attempt rate; use computeAvgScore() for the other.
 */
final class NaturalnessMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $total = 0;
        $firstAttempt = 0;

        foreach ($corpus as $entry) {
            if (($entry['fallback_used'] ?? false) || ($entry['text'] ?? '') === '') {
                continue;
            }

            ++$total;

            $rawAttempts = $entry['attempts'] ?? 1;

            if ((\is_numeric($rawAttempts) ? (int) $rawAttempts : 1) === 1) {
                ++$firstAttempt;
            }
        }

        $rate = $total > 0 ? $firstAttempt / $total : 0.0;

        return new MetricResult(
            'first_attempt_approval',
            'naturalness',
            round($rate, 4),
            0.60,
            'gt',
            $total,
            sprintf('%d/%d replies approved on first attempt', $firstAttempt, $total),
        );
    }

    /**
     * Compute average naturalness score from multi-criteria validator.
     *
     * @param array<int, array<string, mixed>> $corpus
     */
    public function computeAvgScore(array $corpus): MetricResult
    {
        $scores = [];

        foreach ($corpus as $entry) {
            $score = $entry['naturalness'] ?? null;

            if ($score !== null && \is_numeric($score) && $score > 0) {
                $scores[] = (int) $score;
            }
        }

        $count = count($scores);
        $avg = $count > 0 ? array_sum($scores) / $count : 0.0;

        return new MetricResult(
            'avg_naturalness_score',
            'naturalness',
            round($avg, 2),
            3.0,
            'gt',
            $count,
            sprintf('Average naturalness score: %.2f/5 across %d entries', $avg, $count),
        );
    }
}
