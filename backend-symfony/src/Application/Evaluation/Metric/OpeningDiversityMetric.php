<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures opening diversity: ratio of unique first sentences across replies.
 *
 * Target: > 0.80.
 */
final class OpeningDiversityMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $openings = [];

        foreach ($corpus as $entry) {
            $text = $entry['text'] ?? '';

            if ($text === '' || ($entry['fallback_used'] ?? false)) {
                continue;
            }

            $openings[] = $this->extractOpening($text);
        }

        $total = count($openings);

        if ($total === 0) {
            return new MetricResult(
                'opening_diversity',
                'diversity',
                0.0,
                0.80,
                'gt',
                0,
                'No valid replies to analyze',
            );
        }

        $unique = count(array_unique($openings));
        $ratio = $unique / $total;

        return new MetricResult(
            'opening_diversity',
            'diversity',
            round($ratio, 4),
            0.80,
            'gt',
            $total,
            sprintf('%d unique openings out of %d replies', $unique, $total),
        );
    }

    private function extractOpening(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^(.+?)[.!?\n]{1}/u', $text, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        $words = explode(' ', $text);

        return mb_strtolower(implode(' ', array_slice($words, 0, 5)));
    }
}
