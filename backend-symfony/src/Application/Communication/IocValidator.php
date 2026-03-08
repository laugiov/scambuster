<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Validates IOC values based on their type.
 *
 * Supports validation for all IOC types defined in Sprint 3 specification:
 * - Email & headers: email, message_id, subject, x_mailer, return_path, spf_result, dkim_result, dmarc_result
 * - Infrastructure: url, domain, ip (ipv4/ipv6), whois_email, registrar
 * - Hashes: md5, sha256, sha1, file_hash
 * - Finance: iban, bic, wallet_btc, wallet_eth, wallet_xmr
 * - Contact: phone, telegram_username, discord_username, skype_id
 * - Files: filename, mimetype
 * - Security: cve, malware_family, mitre_attack_id
 * - Misc: bank_account, credit_card, whois_registrar_name
 */
final class IocValidator
{
    /**
     * All supported IOC types with their validation patterns.
     */
    private const IOC_PATTERNS = [
        // Email & Headers
        'email' => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}$/',
        'message_id' => '/^<.+@.+>$/',
        'subject' => '/.+/',  // Any non-empty string
        'x_mailer' => '/.+/',
        'return_path' => '/^<.*>$|^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}$/',
        'spf_result' => '/^(pass|fail|softfail|neutral|none|temperror|permerror)$/i',
        'dkim_result' => '/^(pass|fail|neutral|policy|temperror|permerror|none)$/i',
        'dmarc_result' => '/^(pass|fail|none)$/i',

        // Infrastructure
        'url' => '#^(https?://|www\.)[^\s<>"{}|\\^\[\]`]+$#i',
        'domain' => '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i',
        'ipv4' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
        'ipv6' => '/^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/',
        'ip' => null,  // Special case: validate as ipv4 or ipv6
        'whois_email' => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}$/',
        'registrar' => '/.+/',
        'whois_registrar_name' => '/.+/',

        // Hashes
        'md5' => '/^[a-fA-F0-9]{32}$/',
        'sha1' => '/^[a-fA-F0-9]{40}$/',
        'sha256' => '/^[a-fA-F0-9]{64}$/',
        'file_hash' => null,  // Special case: validate as md5, sha1, or sha256

        // Finance
        'iban' => '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/',
        'bic' => '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/',
        'wallet_btc' => '/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{25,62}$/',
        'wallet_eth' => '/^0x[a-fA-F0-9]{40}$/i',
        'wallet_xmr' => '/^[48][0-9AB][1-9A-HJ-NP-Za-km-z]{93}$/',

        // Contact channels
        'phone' => '/^[\d\s\+\(\)\-\.]{7,20}$/',
        'telegram_username' => '/^@[a-zA-Z0-9_]{5,32}$/',
        'discord_username' => '/^.{2,32}#[0-9]{4}$|^[a-z0-9._]{2,32}$/',  // Old format (Name#1234) or new format (username)
        'skype_id' => '/^[a-zA-Z][a-zA-Z0-9\.,\-_]{5,31}$/',

        // Files
        'filename' => '/.+/',
        'mimetype' => '/^[a-zA-Z0-9]+\/[a-zA-Z0-9\-\+\.]+$/',

        // Security identifiers
        'cve' => '/^CVE-\d{4}-\d{4,}$/i',
        'malware_family' => '/.+/',
        'mitre_attack_id' => '/^T\d{4}(\.\d{3})?$/',

        // Financial accounts
        'bank_account' => '/.+/',  // Too variable across countries
        'credit_card' => '/^\d{13,19}$/',  // Luhn validation done separately
    ];

    /**
     * Validate an IOC value based on its type.
     *
     * @param string $type IOC type
     * @param string $value IOC value to validate
     * @return bool True if valid, false otherwise
     */
    public function validate(string $type, string $value): bool
    {
        // Empty values are invalid
        if (trim($value) === '') {
            return false;
        }

        // Special case: ip (validate as ipv4 or ipv6)
        if ($type === 'ip') {
            return $this->validate('ipv4', $value) || $this->validate('ipv6', $value);
        }

        // Special case: file_hash (validate as md5, sha1, or sha256)
        if ($type === 'file_hash') {
            return $this->validate('md5', $value) || $this->validate('sha1', $value) || $this->validate('sha256', $value);
        }

        // Special case: credit_card (validate Luhn checksum)
        if ($type === 'credit_card') {
            return $this->validateCreditCard($value);
        }

        // Unknown type
        if (!isset(self::IOC_PATTERNS[$type])) {
            return false;
        }

        $pattern = self::IOC_PATTERNS[$type];

        return preg_match($pattern, $value) === 1;
    }

    /**
     * Validate credit card number using Luhn algorithm.
     */
    private function validateCreditCard(string $value): bool
    {
        // Remove spaces and hyphens
        $value = preg_replace('/[\s\-]/', '', $value);

        // Check format
        if (!preg_match('/^\d{13,19}$/', $value)) {
            return false;
        }

        // Luhn algorithm
        $sum = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $value[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    /**
     * Get list of all supported IOC types.
     *
     * @return array<string> List of IOC type codes
     */
    public function getSupportedTypes(): array
    {
        return array_keys(self::IOC_PATTERNS);
    }

    /**
     * Check if an IOC type is supported.
     */
    public function isSupportedType(string $type): bool
    {
        return isset(self::IOC_PATTERNS[$type]);
    }
}
