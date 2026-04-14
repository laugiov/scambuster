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
            /** @var string $text */
            $text = $entry['text'] ?? '';

            if ($text === '') {
                continue;
            }

            if ($entry['fallback_used'] ?? false) {
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

    /**
     * Extract the opening signature of a reply for diversity comparison.
     *
     * Strips common greetings ("Hello", "Bonjour", "Hi there") and captures
     * the first 8 meaningful words — this is where persona voice appears.
     */
    private function extractOpening(string $text): string
    {
        $text = trim($text);

        // Strip common email greetings to compare actual content
        $text = (string) preg_replace('/^(?:(?:Hello|Hi|Bonjour|Greetings|Dear\s+\w+)[\s,]*(?:there)?[\s,]*\n*)/iu', '', $text);
        $text = trim($text);

        // Take first 8 words of the actual content
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || $words === []) {
            return '';
        }

        return mb_strtolower(implode(' ', array_slice($words, 0, 8)));
    }
}
