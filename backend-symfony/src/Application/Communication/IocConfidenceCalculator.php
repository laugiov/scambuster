<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Computes IOC confidence scores and temporal decay.
 *
 * - Base confidence: determined by extraction method (regex > LLM)
 * - Multi-observation boost: repeated sightings increase confidence
 * - Temporal decay: IOC relevance decreases over time (half-life model)
 * - Effective score: confidence × decay factor
 */
final class IocConfidenceCalculator
{
    /** @var array<string, float> Extraction method → base confidence */
    private const BASE_CONFIDENCE = [
        'header' => 0.99,
        'regex'  => 0.95,
        'llm'    => 0.75,
    ];

    private const DEFAULT_CONFIDENCE = 0.80;

    /**
     * Get base confidence for an extraction method.
     */
    public static function getBaseConfidence(string $method): float
    {
        return self::BASE_CONFIDENCE[strtolower($method)] ?? self::DEFAULT_CONFIDENCE;
    }

    /**
     * Boost confidence based on number of independent observations.
     *
     * Formula: 1 - (1 - base)^n — converges toward 1.0 as observations increase.
     * Example: base=0.75, n=3 → 1 - 0.25^3 = 1 - 0.015625 = 0.984
     */
    public static function boostConfidence(float $base, int $occurrences): float
    {
        if ($occurrences <= 1) {
            return $base;
        }

        $boosted = 1.0 - (1.0 - $base) ** $occurrences;

        return min($boosted, 1.0);
    }

    /**
     * Compute decay factor based on IOC age and type-specific half-life.
     *
     * Formula: 2^(-age_days / half_life_days)
     * Returns 1.0 for fresh IOCs, 0.5 at half-life, approaches 0 for old IOCs.
     */
    public static function computeDecayFactor(string $iocType, \DateTimeImmutable $lastSeen, ?\DateTimeImmutable $now = null): float
    {
        $now = $now ?? new \DateTimeImmutable();
        $ageDays = max(0, (int) $now->diff($lastSeen)->days);

        if ($ageDays === 0) {
            return 1.0;
        }

        $halfLife = IocDecayConfig::getHalfLifeDays($iocType);

        return 2.0 ** (-$ageDays / $halfLife);
    }

    /**
     * Compute effective score: confidence × decay factor.
     *
     * This is the primary score used for filtering, sorting, and export.
     */
    public static function computeEffectiveScore(
        float $confidence,
        string $iocType,
        \DateTimeImmutable $lastSeen,
        ?\DateTimeImmutable $now = null,
    ): float {
        $decayFactor = self::computeDecayFactor($iocType, $lastSeen, $now);

        return round($confidence * $decayFactor, 4);
    }
}
