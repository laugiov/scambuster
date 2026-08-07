<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\ThreatActor\AnalystVerdict;

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

    /** Corroborating sources needed before repetition lifts confidence at all. */
    private const MIN_SOURCES_TO_BOOST = 2;

    /** Confidence added per corroborating source beyond the first. */
    private const PER_SOURCE_BOOST = 0.05;

    /** Hard cap on the total lift from corroboration. */
    private const MAX_CORROBORATION_BOOST = 0.15;

    /**
     * Boost confidence by the number of INDEPENDENT corroborating sources
     * (distinct conversations that observed the value), never by raw repetition.
     *
     * Re-seeing the same value from a single source is not corroboration — it is a
     * poisoning vector (an adversary re-posting a fabricated IBAN across a baited
     * thread). So a single source never lifts confidence, and the total lift from
     * corroboration is hard-capped (+0.15) so volume alone can never reach ~1.0.
     *
     * @param int $distinctSources number of distinct sources that observed the value
     */
    public static function boostConfidence(float $base, int $distinctSources): float
    {
        if ($distinctSources < self::MIN_SOURCES_TO_BOOST) {
            return $base;
        }

        $boost = min(self::PER_SOURCE_BOOST * ($distinctSources - 1), self::MAX_CORROBORATION_BOOST);

        return min($base + $boost, 1.0);
    }

    /**
     * Compute decay factor based on IOC age and type-specific half-life.
     *
     * Formula: 2^(-age_days / half_life_days)
     * Returns 1.0 for fresh IOCs, 0.5 at half-life, approaches 0 for old IOCs.
     */
    public static function computeDecayFactor(string $iocType, \DateTimeImmutable $lastSeen, ?\DateTimeImmutable $now = null): float
    {
        $now ??= new \DateTimeImmutable();
        $ageDays = max(0, (int) $now->diff($lastSeen)->days);

        if ($ageDays === 0) {
            return 1.0;
        }

        $halfLife = IocDecayConfig::getHalfLifeDays($iocType);

        return 2.0 ** (-$ageDays / $halfLife);
    }

    /** @var array<string, string> IOC types considered HIGH value by nature (used as clustering anchors) */
    private const HIGH_VALUE_TYPES = [
        'iban' => 'HIGH', 'bank_account' => 'HIGH', 'credit_card' => 'HIGH',
        'wallet_btc' => 'HIGH', 'wallet_eth' => 'HIGH', 'wallet_xmr' => 'HIGH',
        'phone' => 'HIGH',
    ];

    /** @var array<string, string> IOC types considered MEDIUM value by nature */
    private const MEDIUM_VALUE_TYPES = [
        'bic' => 'MEDIUM', // BIC identifies a bank (millions of clients), not a threat actor
        'url' => 'MEDIUM', 'domain' => 'MEDIUM', 'email' => 'MEDIUM', 'whois_email' => 'MEDIUM',
        'ipv4' => 'MEDIUM', 'ipv6' => 'MEDIUM',
        'sha256' => 'MEDIUM', 'sha1' => 'MEDIUM', 'md5' => 'MEDIUM',
        'filename' => 'MEDIUM', 'registrar' => 'MEDIUM',
    ];

    /**
     * Compute severity based on IOC type and enrichment score.
     *
     * Logic:
     * - Financial IOCs (IBAN, crypto, phone) → HIGH regardless of VT score
     * - Network IOCs (URL, domain, IP, email) → MEDIUM, upgraded to HIGH if VT > 0
     * - Metadata IOCs (subject, message_id) → LOW
     * - Unknown types → LOW
     */
    public static function computeSeverity(string $iocType, int $vtScore = 0, int $urlscanScore = 0): string
    {
        $type = strtolower($iocType);
        $enrichmentScore = max($vtScore, $urlscanScore);

        // HIGH-value types are always HIGH
        if (isset(self::HIGH_VALUE_TYPES[$type])) {
            return 'HIGH';
        }

        // MEDIUM types upgrade to HIGH if enrichment confirms threat
        if (isset(self::MEDIUM_VALUE_TYPES[$type])) {
            return $enrichmentScore > 0 ? 'HIGH' : 'MEDIUM';
        }

        // Everything else (subject, message_id, dmarc_result, etc.)
        return 'LOW';
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

    // Analyst-feedback overrides — an explicit human verdict outranks the computed
    // confidence: confirmed pins it high, false-positive drops it near zero.
    public const CONFIRMED_CONFIDENCE = 0.99;
    public const FALSE_POSITIVE_CONFIDENCE = 0.05;

    /**
     * Fold an analyst verdict into a computed confidence. Null verdict = unchanged.
     */
    public static function applyAnalystVerdict(float $base, ?AnalystVerdict $verdict): float
    {
        return match ($verdict) {
            AnalystVerdict::Confirmed => max($base, self::CONFIRMED_CONFIDENCE),
            AnalystVerdict::FalsePositive => self::FALSE_POSITIVE_CONFIDENCE,
            null => $base,
        };
    }
}
