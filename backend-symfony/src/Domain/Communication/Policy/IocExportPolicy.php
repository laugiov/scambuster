<?php

declare(strict_types=1);

namespace App\Domain\Communication\Policy;

use App\Domain\Communication\IocCategory;
use App\Domain\ThreatActor\AnalystVerdict;

/**
 * Single source of truth for "may this IOC leave the platform" — applied by
 * every egress surface (TAXII feed, STIX export handlers, MISP export).
 *
 * Two rules, both fail-closed:
 *
 *   1. An analyst-declared false positive never exports, whatever its type.
 *      A human said "this is wrong"; shipping it anyway (even at reduced
 *      confidence) poisons downstream consumers.
 *
 *   2. Financial IOCs (IBAN, wallets, card/account numbers — see
 *      IocCategory::FINANCIAL_TYPES) are HELD until an analyst confirms
 *      them. Mule-account holders are frequently scam victims themselves;
 *      an unreviewed account number in a shared CTI feed can land a victim
 *      on consumer blocklists. Release path: the analyst-feedback verdict
 *      `confirmed`.
 *
 *   3. Non-financial IOCs (domains, URLs, IPs, emails…) export only once
 *      CORROBORATED — observed in at least MIN_CORROBORATION independent
 *      conversations — or explicitly analyst-confirmed. A value seen in a
 *      single conversation may be an innocent third party swept up once; a
 *      value recurring across independent conversations is the actor's real
 *      infrastructure. This is graduated, not a blanket hold: the bulk of a
 *      mature feed is corroborated and still ships automatically.
 *
 * This policy composes with — and never replaces — the existing egress
 * filters (TLP:RED never-public, IocActionablePolicy non-actionable set).
 */
final class IocExportPolicy
{
    /**
     * Minimum number of independent conversations a non-financial IOC must be
     * observed in before it ships without an analyst verdict.
     */
    public const MIN_CORROBORATION = 2;

    /**
     * A financial IOC that no analyst has reviewed yet: held from export,
     * waiting in the review queue. Distinct from a false positive (rejected,
     * not waiting) — the read APIs expose this so the UI can badge and filter
     * the analyst's worklist.
     */
    public static function isHeldForReview(string $type, ?AnalystVerdict $verdict): bool
    {
        return $verdict === null && IocCategory::classify($type) === IocCategory::FINANCIAL;
    }

    /**
     * PHP-side predicate for egress paths that assemble objects in memory.
     *
     * @param int $corroborationCount distinct conversations that observed the
     *                                IOC (ignored for financial types)
     */
    public static function isExportable(string $type, ?AnalystVerdict $verdict, int $corroborationCount = 0): bool
    {
        if ($verdict === AnalystVerdict::FalsePositive) {
            return false;
        }

        if ($verdict === AnalystVerdict::Confirmed) {
            return true;
        }

        if (IocCategory::classify($type) === IocCategory::FINANCIAL) {
            // Financial: analyst confirmation only (already returned above).
            return false;
        }

        // Non-financial without a verdict: ship only once corroborated.
        return $corroborationCount >= self::MIN_CORROBORATION;
    }

    /**
     * Self-contained SQL fragment for egress paths that filter at query level
     * (pagination stays skip-free). Expects the indicator alias and a
     * LEFT-JOINed ioc_analyst_feedback alias. Every value inlined here is a
     * compile-time constant (enum values + IocCategory::FINANCIAL_TYPES), so
     * the fragment is safe to embed in queries using positional parameters.
     *
     * The stored type is compared through LOWER(BTRIM(...)) — ingest stores the
     * caller-provided type verbatim, and the hold must match the same
     * normalization IocCategory::classify() applies (case-insensitive, trimmed)
     * so a mixed-case or padded financial type cannot bypass it.
     */
    public static function sqlCondition(string $indicatorAlias, string $feedbackAlias): string
    {
        $verdict = "{$feedbackAlias}.verdict";
        $type = "LOWER(BTRIM({$indicatorAlias}.type))";
        $heldTypes = implode(', ', array_map(
            static fn (string $t): string => "'" . $t . "'",
            IocCategory::FINANCIAL_TYPES,
        ));

        $confirmed = $verdict . " = '" . AnalystVerdict::Confirmed->value . "'";

        // Distinct conversations that observed this indicator. Correlated
        // subquery on the indicator alias; indexed by observed_ioc(indicator_id).
        $corroboration = '(SELECT COUNT(DISTINCT m_c.conv_id) FROM observed_ioc oi_c'
            . ' JOIN message m_c ON oi_c.msg_id = m_c.msg_id'
            . " WHERE oi_c.indicator_id = {$indicatorAlias}.indicator_id) >= " . self::MIN_CORROBORATION;

        return "({$verdict} IS NULL OR {$verdict} <> '" . AnalystVerdict::FalsePositive->value . "')"
            // Financial: analyst confirmation only.
            . " AND ({$type} NOT IN ({$heldTypes}) OR {$confirmed})"
            // Non-financial: analyst confirmation OR corroboration across conversations.
            . " AND ({$type} IN ({$heldTypes}) OR {$confirmed} OR {$corroboration})";
    }
}
