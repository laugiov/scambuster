<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Maps IOC types to MISP and STIX 2.1 export formats.
 *
 * This service enriches IOC context with metadata required for:
 * - MISP Event export (category, type, to_ids)
 * - STIX 2.1 Observed-Data export (SCO type, patterns)
 *
 * Supports all IOC types (40+ types):
 * - Email & headers, infrastructure, hashes, finance, contact channels,
 * - files, security identifiers, authentication results
 */
final class IocExportMapper
{
    /**
     * Mapping IOC type → MISP attributes.
     *
     * @var array<string, array{category: string, type: string, to_ids: bool}>
     */
    private const MISP_MAPPING = [
        // Email & Headers
        'email' => ['category' => 'Network activity', 'type' => 'email-src', 'to_ids' => true],
        'whois_email' => ['category' => 'Network activity', 'type' => 'whois-registrant-email', 'to_ids' => true],
        'message_id' => ['category' => 'Network activity', 'type' => 'email-message-id', 'to_ids' => false],
        'subject' => ['category' => 'Network activity', 'type' => 'email-subject', 'to_ids' => false],
        'x_mailer' => ['category' => 'Network activity', 'type' => 'email-x-mailer', 'to_ids' => false],
        'return_path' => ['category' => 'Network activity', 'type' => 'email-header', 'to_ids' => false],
        'spf_result' => ['category' => 'Network activity', 'type' => 'email-header', 'to_ids' => false],
        'dkim_result' => ['category' => 'Network activity', 'type' => 'dkim-signature', 'to_ids' => false],
        'dmarc_result' => ['category' => 'Network activity', 'type' => 'email-header', 'to_ids' => false],

        // Infrastructure
        'url' => ['category' => 'Network activity', 'type' => 'url', 'to_ids' => true],
        'domain' => ['category' => 'Network activity', 'type' => 'domain', 'to_ids' => true],
        'ip' => ['category' => 'Network activity', 'type' => 'ip-src', 'to_ids' => true],
        'ipv4' => ['category' => 'Network activity', 'type' => 'ip-src', 'to_ids' => true],
        'ipv6' => ['category' => 'Network activity', 'type' => 'ip-src', 'to_ids' => true],
        'registrar' => ['category' => 'Network activity', 'type' => 'whois-registrar', 'to_ids' => false],
        'whois_registrar_name' => ['category' => 'Network activity', 'type' => 'whois-registrar', 'to_ids' => false],

        // Hashes
        'md5' => ['category' => 'Payload delivery', 'type' => 'md5', 'to_ids' => true],
        'sha1' => ['category' => 'Payload delivery', 'type' => 'sha1', 'to_ids' => true],
        'sha256' => ['category' => 'Payload delivery', 'type' => 'sha256', 'to_ids' => true],
        'hash' => ['category' => 'Payload delivery', 'type' => 'sha256', 'to_ids' => true],
        'file_hash' => ['category' => 'Payload delivery', 'type' => 'sha256', 'to_ids' => true],

        // Finance
        'iban' => ['category' => 'Financial fraud', 'type' => 'iban', 'to_ids' => true],
        'bic' => ['category' => 'Financial fraud', 'type' => 'bic', 'to_ids' => true],
        'wallet_btc' => ['category' => 'Financial fraud', 'type' => 'btc', 'to_ids' => true],
        'wallet_eth' => ['category' => 'Financial fraud', 'type' => 'crypto-wallet', 'to_ids' => true],
        'wallet_xmr' => ['category' => 'Financial fraud', 'type' => 'xmr', 'to_ids' => true],
        'bank_account' => ['category' => 'Financial fraud', 'type' => 'bank-account-nr', 'to_ids' => true],
        'credit_card' => ['category' => 'Financial fraud', 'type' => 'cc-number', 'to_ids' => true],

        // Contact channels
        'phone' => ['category' => 'Person', 'type' => 'phone-number', 'to_ids' => false],
        'telegram_username' => ['category' => 'Social network', 'type' => 'telegram-account', 'to_ids' => false],
        'discord_username' => ['category' => 'Social network', 'type' => 'other', 'to_ids' => false],
        'skype_id' => ['category' => 'Social network', 'type' => 'other', 'to_ids' => false],

        // Files
        'filename' => ['category' => 'Payload delivery', 'type' => 'filename', 'to_ids' => false],
        'mimetype' => ['category' => 'Payload delivery', 'type' => 'mime-type', 'to_ids' => false],

        // Security identifiers
        'cve' => ['category' => 'External analysis', 'type' => 'vulnerability', 'to_ids' => false],
        'malware_family' => ['category' => 'Payload delivery', 'type' => 'malware-type', 'to_ids' => false],
        'mitre_attack_id' => ['category' => 'External analysis', 'type' => 'other', 'to_ids' => false],

        // Logistics
        'tracking_number' => ['category' => 'External analysis', 'type' => 'other', 'to_ids' => false],

        // Identity / Location. Person/other is the closest
        // generic MISP attribute; to_ids=false because addresses are
        // pivots, not blocklist entries (no automated detection signal).
        'postal_address' => ['category' => 'Person', 'type' => 'other', 'to_ids' => false],
    ];

    /**
     * Mapping IOC type → STIX 2.1 SCO (Cyber Observable Object) types.
     *
     * @var array<string, string>
     */
    private const STIX_SCO_MAPPING = [
        // Email & Headers
        'email' => 'email-addr',
        'whois_email' => 'email-addr',
        'message_id' => 'email-message',
        'subject' => 'email-message',
        'x_mailer' => 'email-message',
        'return_path' => 'email-message',
        'spf_result' => 'email-message',
        'dkim_result' => 'email-message',
        'dmarc_result' => 'email-message',

        // Infrastructure
        'url' => 'url',
        'domain' => 'domain-name',
        'ip' => 'ipv4-addr',  // Default to ipv4
        'ipv4' => 'ipv4-addr',
        'ipv6' => 'ipv6-addr',
        'registrar' => 'x-scambuster-registrar',
        'whois_registrar_name' => 'x-scambuster-registrar',

        // Hashes
        'md5' => 'file',
        'sha1' => 'file',
        'sha256' => 'file',
        'hash' => 'file',
        'file_hash' => 'file',

        // Finance
        'iban' => 'x-scambuster-iban',
        'bic' => 'x-scambuster-bic',
        'wallet_btc' => 'x-scambuster-crypto-wallet',
        'wallet_eth' => 'x-scambuster-crypto-wallet',
        'wallet_xmr' => 'x-scambuster-crypto-wallet',
        'bank_account' => 'x-scambuster-bank-account',
        'credit_card' => 'x-scambuster-credit-card',

        // Contact channels
        'phone' => 'x-scambuster-phone',
        'telegram_username' => 'user-account',
        'discord_username' => 'user-account',
        'skype_id' => 'user-account',

        // Files
        'filename' => 'file',
        'mimetype' => 'file',

        // Security identifiers
        'cve' => 'vulnerability',
        'malware_family' => 'malware',
        'mitre_attack_id' => 'attack-pattern',

        // Logistics
        'tracking_number' => 'x-scambuster-tracking-number',

        // Identity / Location.
        // Custom SCO, consistent with x-scambuster-phone / -iban / etc.
        // Pattern emitted: [x-scambuster-postal-address:value = '...'].
        'postal_address' => 'x-scambuster-postal-address',
    ];

    /**
     * Enrich IOC context with MISP and STIX export metadata.
     *
     * Adds two new keys to the IOC context:
     * - misp: {category, type, to_ids}
     * - stix: {sco_type, pattern}
     *
     * @param array<string, mixed> $iocContext Original IOC context
     *
     * @return array<string, mixed> Enriched IOC context
     */
    public function enrichWithExportMetadata(array $iocContext): array
    {
        /** @var string $type */
        $type = $iocContext['type'] ?? 'unknown';

        // Add MISP metadata
        $iocContext['misp'] = self::MISP_MAPPING[$type] ?? [
            'category' => 'Other',
            'type' => 'other',
            'to_ids' => false,
        ];

        // Add STIX metadata
        $iocContext['stix'] = [
            'sco_type' => self::STIX_SCO_MAPPING[$type] ?? 'artifact',
            'pattern' => $this->buildStixPattern($type, \is_string($iocContext['value_norm'] ?? null) ? $iocContext['value_norm'] : ''),
        ];

        return $iocContext;
    }

    /**
     * Build STIX 2.1 pattern from IOC type and value.
     *
     * Pattern format: [sco-type:value = 'escaped-value']
     * Example: [email-addr:value = 'attacker@evil.com']
     *
     * @param string $type  IOC type
     * @param string $value Normalized IOC value
     *
     * @return string STIX 2.1 pattern
     */
    private function buildStixPattern(string $type, string $value): string
    {
        $scoType = self::STIX_SCO_MAPPING[$type] ?? 'artifact';

        // Escape single quotes in value for STIX pattern syntax
        $escapedValue = str_replace("'", "\\'", $value);

        return "[{$scoType}:value = '{$escapedValue}']";
    }
}
