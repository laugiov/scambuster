<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Maps ScamBuster scam-type identifiers to standard CTI machine tags for export.
 *
 * Pure and stateless — no I/O. The taxonomy identifiers come from the caller
 * (the `lkp_scam_type` row: RSIT `misp_taxonomy` + `attck_technique`); this class
 * only turns them into the exact tag strings MISP consumers expect.
 *
 * MISP-galaxy grounding: a `mitre-attack-pattern` galaxy tag is
 * `misp-galaxy:mitre-attack-pattern="<name> - <id>"`. The id is first-party (stored
 * in `lkp_scam_type.attck_technique`); the <name> is an external CTI display string,
 * so the five names below are AUTHORITATIVELY VERIFIED (attack.mitre.org + the MISP
 * galaxy viewer, 2026-07-07) rather than inferred. These five are exactly the distinct
 * techniques present in `lkp_scam_type`. Any technique NOT in this map yields NO galaxy
 * tag (fail-safe) — we never ship a fabricated galaxy string under uncertainty.
 */
final class ScamTaxonomyMapper
{
    /**
     * ATT&CK technique id → verified MISP `mitre-attack-pattern` galaxy cluster value.
     *
     * @var array<string, string>
     */
    private const ATTCK_GALAXY_VALUE = [
        'T1566' => 'Phishing - T1566',
        'T1566.001' => 'Spearphishing Attachment - T1566.001',
        'T1566.002' => 'Spearphishing Link - T1566.002',
        'T1566.003' => 'Spearphishing via Service - T1566.003',
        'T1656' => 'Impersonation - T1656',
    ];

    /**
     * MISP `mitre-attack-pattern` galaxy tag for an ATT&CK technique id, or null when
     * the id is absent or not in the verified map (never a fabricated string).
     */
    public function attckGalaxyTag(?string $attckId): ?string
    {
        if ($attckId === null || $attckId === '') {
            return null;
        }

        $value = self::ATTCK_GALAXY_VALUE[$attckId] ?? null;

        return $value === null ? null : 'misp-galaxy:mitre-attack-pattern="' . $value . '"';
    }

    /**
     * The RSIT taxonomy machine tag stored in `lkp_scam_type.misp_taxonomy`
     * (e.g. `rsit:fraud="phishing"`), passed through verbatim, or null when absent.
     */
    public function rsitTag(?string $mispTaxonomy): ?string
    {
        if ($mispTaxonomy === null) {
            return null;
        }

        $trimmed = trim($mispTaxonomy);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A first-party scam-type machine tag, for round-trip fidelity of the
     * ScamBuster code alongside the standard RSIT/ATT&CK tags.
     */
    public function scamTypeTag(string $code): string
    {
        return 'scambuster:scam-type="' . strtoupper($code) . '"';
    }
}
