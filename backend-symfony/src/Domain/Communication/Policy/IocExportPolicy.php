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
 *      `confirmed` (see docs/24_analyst_feedback.md and
 *      docs/compliance/mule-victim-account-policy.md).
 *
 * This policy composes with — and never replaces — the existing egress
 * filters (TLP:RED never-public, IocActionablePolicy non-actionable set).
 */
final class IocExportPolicy
{
    /**
     * PHP-side predicate for egress paths that assemble objects in memory.
     */
    public static function isExportable(string $type, ?AnalystVerdict $verdict): bool
    {
        if ($verdict === AnalystVerdict::FalsePositive) {
            return false;
        }

        if (IocCategory::classify($type) === IocCategory::FINANCIAL) {
            return $verdict === AnalystVerdict::Confirmed;
        }

        return true;
    }

    /**
     * Self-contained SQL fragment for egress paths that filter at query level
     * (pagination stays skip-free). Expects the indicator alias and a
     * LEFT-JOINed ioc_analyst_feedback alias. Every value inlined here is a
     * compile-time constant (enum values + IocCategory::FINANCIAL_TYPES), so
     * the fragment is safe to embed in queries using positional parameters.
     */
    public static function sqlCondition(string $indicatorAlias, string $feedbackAlias): string
    {
        $verdict = "{$feedbackAlias}.verdict";
        $type = "{$indicatorAlias}.type";
        $heldTypes = implode(', ', array_map(
            static fn (string $t): string => "'" . $t . "'",
            IocCategory::FINANCIAL_TYPES,
        ));

        return "({$verdict} IS NULL OR {$verdict} <> '" . AnalystVerdict::FalsePositive->value . "')"
            . " AND ({$type} NOT IN ({$heldTypes}) OR {$verdict} = '" . AnalystVerdict::Confirmed->value . "')";
    }
}
