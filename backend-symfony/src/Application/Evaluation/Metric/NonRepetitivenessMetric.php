<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures non-repetitiveness via Jaccard similarity on character trigrams
 * between consecutive replies in the same conversation.
 *
 * Target: average similarity < 0.30.
 */
final class NonRepetitivenessMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $byConversation = [];

        foreach ($corpus as $entry) {
            $convId = $entry['conv_id'] ?? 'unknown';
            $byConversation[$convId][] = $entry['text'] ?? '';
        }

        $similarities = [];

        foreach ($byConversation as $replies) {
            if (count($replies) < 2) {
                continue;
            }

            for ($i = 1, $n = count($replies); $i < $n; ++$i) {
                $sim = $this->jaccardTrigram($replies[$i - 1], $replies[$i]);
                $similarities[] = $sim;
            }
        }

        $sampleSize = count($similarities);

        if ($sampleSize === 0) {
            return new MetricResult(
                'non_repetitiveness',
                'diversity',
                0.0,
                0.30,
                'lt',
                0,
                'No consecutive reply pairs found in same conversation',
            );
        }

        $avgSimilarity = array_sum($similarities) / $sampleSize;

        return new MetricResult(
            'non_repetitiveness',
            'diversity',
            round($avgSimilarity, 4),
            0.30,
            'lt',
            $sampleSize,
            sprintf('Average Jaccard trigram similarity across %d consecutive pairs', $sampleSize),
            minSampleSize: 5,
        );
    }

    private function jaccardTrigram(string $a, string $b): float
    {
        $triA = $this->trigrams($a);
        $triB = $this->trigrams($b);

        if ($triA === [] && $triB === []) {
            return 1.0;
        }

        if ($triA === [] || $triB === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($triA, $triB));
        $union = count(array_unique(array_merge(array_keys($triA), array_keys($triB))));

        // Both arrays are non-empty at this point, so union is always >= 1
        return $intersection / $union;
    }

    /**
     * @return array<string, true>
     */
    private function trigrams(string $text): array
    {
        $text = mb_strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? '');
        $len = mb_strlen($text);

        if ($len < 3) {
            return [];
        }

        $result = [];

        for ($i = 0; $i <= $len - 3; ++$i) {
            $result[mb_substr($text, $i, 3)] = true;
        }

        return $result;
    }
}
