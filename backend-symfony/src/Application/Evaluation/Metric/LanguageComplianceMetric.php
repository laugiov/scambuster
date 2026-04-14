<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Metric;

/**
 * Measures language compliance: % of replies matching the scammer's detected language.
 *
 * Target: > 0.95.
 */
final class LanguageComplianceMetric implements MetricInterface
{
    public function compute(array $corpus): MetricResult
    {
        $total = 0;
        $matches = 0;

        foreach ($corpus as $entry) {
            $detected = $entry['detected_language'] ?? null;
            $reply = $entry['reply_language'] ?? null;

            if ($detected === null) {
                continue;
            }

            if ($reply === null) {
                continue;
            }

            if ($entry['fallback_used'] ?? false) {
                continue;
            }

            ++$total;

            if ($detected === $reply) {
                ++$matches;
            }
        }

        $rate = $total > 0 ? $matches / $total : 0.0;

        return new MetricResult(
            'language_compliance',
            'language',
            round($rate, 4),
            0.95,
            'gt',
            $total,
            sprintf('%d/%d replies match scammer language', $matches, $total),
        );
    }
}
