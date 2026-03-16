<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Normalizes IOC values to a canonical format for storage and comparison.
 *
 * Normalization rules per type (based on Sprint 3 spec):
 * - email, whois_email: lowercase, trim
 * - url: defang (hxxp, [dot]), lowercase domain, trim trailing slash
 * - domain: lowercase, defang ([dot])
 * - ip, ipv4, ipv6: canonical format
 * - md5, sha1, sha256, file_hash: lowercase hex
 * - iban: remove spaces, uppercase
 * - bic: uppercase
 * - wallet_*: as-is (case sensitive)
 * - phone: remove spaces/hyphens, attempt E.164 format
 * - telegram_username, discord_username, skype_id: lowercase
 * - message_id, subject, x_mailer, return_path, filename: as-is
 * - spf_result, dkim_result, dmarc_result: lowercase
 * - cve: uppercase
 * - mitre_attack_id: uppercase
 * - mimetype: lowercase
 */
final class IocNormalizer
{
    /**
     * Normalize an IOC value based on its type.
     *
     * @param string $type  IOC type
     * @param string $value IOC value to normalize
     *
     * @return string Normalized value
     */
    public function normalize(string $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            // Email & headers
            'email', 'whois_email' => $this->normalizeEmail($value),
            'message_id' => $value,  // Keep as-is
            'subject' => $value,  // Keep as-is
            'x_mailer' => $value,  // Keep as-is
            'return_path' => $value,  // Keep as-is
            'spf_result', 'dkim_result', 'dmarc_result' => strtolower($value),

            // Infrastructure
            'url' => $this->normalizeUrl($value),
            'domain' => $this->normalizeDomain($value),
            'ip', 'ipv4', 'ipv6' => $this->normalizeIp($value),
            'registrar', 'whois_registrar_name' => $value,  // Keep as-is

            // Hashes
            'md5', 'sha1', 'sha256', 'file_hash' => strtolower($value),

            // Finance
            'iban' => $this->normalizeIban($value),
            'bic' => strtoupper($value),
            'wallet_btc', 'wallet_eth', 'wallet_xmr' => $value,  // Case sensitive

            // Contact channels
            'phone' => $this->normalizePhone($value),
            'telegram_username' => strtolower($value),
            'discord_username' => strtolower($value),
            'skype_id' => strtolower($value),

            // Files
            'filename' => $value,  // Keep as-is (case sensitive)
            'mimetype' => strtolower($value),

            // Security identifiers
            'cve' => strtoupper($value),
            'malware_family' => $value,  // Keep as-is
            'mitre_attack_id' => strtoupper($value),

            // Financial accounts
            'bank_account' => $value,  // Keep as-is
            'credit_card' => $this->normalizeCreditCard($value),

            // Default: keep as-is
            default => $value,
        };
    }

    /**
     * Normalize email address: lowercase, trim.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Normalize URL: defang (hxxp, [dot]), lowercase domain, remove trailing slash.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        // Defang: http -> hxxp, https -> hxxps
        $url = preg_replace_callback('/^https?/', function ($matches) {
            return str_replace('http', 'hxxp', $matches[0]);
        }, $url);

        // Defang dots in domain part
        if (preg_match('#^(hxxps?://)(www\.)?([^/]+)(.*)$#i', $url, $matches)) {
            $protocol = $matches[1];
            $www = $matches[2];
            $domain = $matches[3];
            $path = $matches[4];

            // Defang domain dots
            $domain = str_replace('.', '[.]', $domain);

            $url = $protocol . $www . $domain . $path;
        }

        // Remove trailing slash
        $url = rtrim($url, '/');

        // Lowercase (defanged URLs should be lowercase for comparison)
        return strtolower($url);
    }

    /**
     * Normalize domain: lowercase, defang ([dot]).
     */
    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        // Defang dots
        return str_replace('.', '[.]', $domain);
    }

    /**
     * Normalize IP address to canonical format.
     */
    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);

        // IPv4: convert to canonical format
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;  // Already canonical
        }

        // IPv6: convert to canonical format
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Expand compressed IPv6
            $packed = inet_pton($ip);

            return $packed !== false ? (inet_ntop($packed) ?: $ip) : $ip;
        }

        // Invalid IP, return as-is
        return $ip;
    }

    /**
     * Normalize IBAN: remove spaces, uppercase.
     */
    private function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban));
    }

    /**
     * Normalize phone number: remove spaces/hyphens/dots/parentheses but keep leading +.
     */
    private function normalizePhone(string $phone): string
    {
        // Keep the leading + if present
        $phone = trim($phone);
        $prefix = '';

        if (str_starts_with($phone, '+')) {
            $prefix = '+';
            $phone = substr($phone, 1);  // Remove + temporarily
        }

        // Remove common separators but NOT the +
        $phone = preg_replace('/[\s\-\.\(\)]/', '', $phone);

        // Re-add the + prefix if it was present
        return $prefix . $phone;
    }

    /**
     * Normalize credit card number: remove spaces and hyphens.
     */
    private function normalizeCreditCard(string $card): string
    {
        return preg_replace('/[\s\-]/', '', $card);
    }

    /**
     * Defang a URL or domain for safe storage/display.
     *
     * - http -> hxxp, https -> hxxps
     * - . -> [.]
     */
    public function defang(string $value): string
    {
        // Replace http/https
        $value = preg_replace_callback('/https?/i', function ($matches) {
            return str_replace('http', 'hxxp', strtolower($matches[0]));
        }, $value);

        // Replace dots
        $value = str_replace('.', '[.]', $value);

        return $value;
    }

    /**
     * Refang a defanged URL or domain for export/display.
     *
     * - hxxp -> http, hxxps -> https
     * - [.] -> .
     */
    public function refang(string $value): string
    {
        // Replace hxxp/hxxps
        $value = preg_replace_callback('/hxxps?/i', function ($matches) {
            return str_replace('hxxp', 'http', strtolower($matches[0]));
        }, $value);

        // Replace [.]
        $value = str_replace('[.]', '.', $value);

        return $value;
    }
}
