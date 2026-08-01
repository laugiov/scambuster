<?php

declare(strict_types=1);

namespace App\Domain\Clustering\ValueObject;

/**
 * Normalizes IOC values for clustering comparison.
 *
 * Two IOC values that represent the same real-world entity must normalize
 * to the same string, regardless of formatting differences (spaces, dashes,
 * case). This is critical for Union-Find clustering: without normalization,
 * "FR76 3000 6000 0112 3456 7890 189" and "FR7630006000011234567890189"
 * would be treated as different IOCs and miss a cluster.
 *
 * Rules per IOC type:
 * - iban: uppercase, strip spaces/dashes
 * - wallet_btc: trim only (Bitcoin addresses are case-sensitive)
 * - wallet_eth: lowercase + trim (Ethereum is case-insensitive)
 * - wallet_xmr: trim only
 * - phone: strip all non-digit/non-plus chars
 * - bic, bank_account: uppercase + trim
 * - credit_card: strip spaces/dashes
 * - default: lowercase + trim
 *
 * @see \App\Application\Communication\IocConfidenceCalculator for severity classification
 */
final class NormalizedIocValue
{
    /**
     * Normalize an IOC value based on its type.
     *
     * @param string $iocType  The IOC type (e.g., 'iban', 'wallet_btc', 'phone')
     * @param string $rawValue The raw value as extracted
     *
     * @return string The normalized value, suitable for comparison and hashing
     *
     * Time complexity: O(N) where N = length of rawValue
     */
    public static function normalize(string $iocType, string $rawValue): string
    {
        return match (strtolower($iocType)) {
            'iban' => strtoupper(preg_replace('/[\s\-]/', '', $rawValue) ?? $rawValue),
            'wallet_btc' => trim($rawValue),
            'wallet_eth' => strtolower(trim($rawValue)),
            'wallet_xmr' => trim($rawValue),
            'phone' => preg_replace('/[^+\d]/', '', $rawValue) ?? $rawValue,
            'bic' => strtoupper(trim($rawValue)),
            'bank_account' => strtoupper(preg_replace('/[\s\-]/', '', $rawValue) ?? $rawValue),
            'credit_card' => preg_replace('/[\s\-]/', '', $rawValue) ?? $rawValue,
            default => strtolower(trim($rawValue)),
        };
    }

    /**
     * Compute a SHA-256 hash of a normalized value.
     *
     * Used to store anchor IOC references in the cluster table without
     * exposing the raw value (GDPR compliance — IBANs and wallets are sensitive).
     *
     * @param string $normalizedValue The output of normalize()
     *
     * @return string 64-character lowercase hex SHA-256 hash
     *
     * Time complexity: O(N) where N = length of normalizedValue
     */
    public static function hash(string $normalizedValue): string
    {
        return hash('sha256', $normalizedValue);
    }
}
