<?php

declare(strict_types=1);

namespace App\Domain\Communication\Policy;

/**
 * Single source of truth for "what counts as an actionable IOC" in the
 * scam-baiting threat model.
 *
 * Three different conversation-count surfaces (list, detail, Theater)
 * historically used three different exclusion lists, producing three
 * different numbers for the same conversation. This policy unifies them.
 *
 * The "non-actionable" set is biased toward our threat model:
 *
 *   - Header metadata (subject, message_id, x_mailer, return_path)
 *     identifies the email envelope, not the scammer's operational
 *     infrastructure.
 *   - Auth results (SPF/DKIM/DMARC) are mail-transport checks; the
 *     scammer doesn't control them.
 *   - WHOIS metadata (whois_email, whois_registrar_name, registrar)
 *     identifies the domain operator, useful for enrichment but not a
 *     pivot on the scammer.
 *   - File metadata (filename, mimetype) describes an attachment
 *     shape, not its content.
 *   - Reference identifiers (cve, malware_family, mitre_attack_id,
 *     tracking_number) are categorical labels, not artefacts.
 *   - File hashes (md5/sha1/sha256) ARE actionable for malware-delivery
 *     IOCs, but the scam-baiting platform does not currently engage in
 *     payload analysis; treating them as actionable inflates the
 *     count with hashes of legitimate attachments (PDFs, images) the
 *     scammer copy-pasted from real corporate emails.
 *
 * Future revisit: if the platform adds attachment-analysis features,
 * promote `md5/sha1/sha256` back to actionable.
 */
final class IocActionablePolicy
{
    /**
     * @var list<string>
     */
    public const NON_ACTIONABLE_TYPES = [
        // Header metadata
        'subject',
        'message_id',
        'x_mailer',
        'return_path',

        // Auth results
        'spf_result',
        'dkim_result',
        'dmarc_result',

        // WHOIS metadata
        'whois_email',
        'whois_registrar_name',
        'registrar',

        // File metadata
        'filename',
        'mimetype',

        // Reference identifiers (not artefacts on their own)
        'cve',
        'malware_family',
        'mitre_attack_id',
        'tracking_number',

        // File hashes (irrelevant to scam-baiting threat model — see
        // class docblock; revisit if attachment analysis is added).
        'md5',
        'sha1',
        'sha256',
    ];

    /**
     * Is the given IOC type considered actionable threat intelligence
     * under the current threat model?
     */
    public static function isActionable(string $type): bool
    {
        return !\in_array(strtolower($type), self::NON_ACTIONABLE_TYPES, true);
    }

    /**
     * @return list<string>
     */
    public static function nonActionableTypes(): array
    {
        return self::NON_ACTIONABLE_TYPES;
    }
}
