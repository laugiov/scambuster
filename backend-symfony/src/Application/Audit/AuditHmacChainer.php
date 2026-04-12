<?php

declare(strict_types=1);

namespace App\Application\Audit;

/**
 * Spec 065f — Computes HMAC-SHA256 chains for the audit_log table.
 *
 * Each new audit row's HMAC depends on the previous row's HMAC,
 * forming a tamper-evident chain: modifying any row in the middle
 * invalidates every subsequent HMAC.
 *
 * Algorithm: `row_hmac = HMAC-SHA256(key, prev_hmac_bin || canonical_json)`
 * where `canonical_json = json_encode(sorted_row_fields)`.
 *
 * The key is read from the `AUDIT_HMAC_KEY` env var (64 hex chars
 * = 32 bytes) at construction time.
 *
 * Graceful degradation: if the key is empty or invalid, the chainer
 * is disabled — compute() returns '' and isEnabled() returns false.
 * This prevents Railway and other deployments without AUDIT_HMAC_KEY
 * from crashing on login (the AUTH events are blocking in AuditLogger).
 *
 * Thread safety: stateless after construction.
 */
final class AuditHmacChainer
{
    private readonly ?string $key;
    private readonly bool $enabled;

    public function __construct(string $hmacKeyHex = '')
    {
        if ($hmacKeyHex === '' || strlen($hmacKeyHex) !== 64 || !ctype_xdigit($hmacKeyHex)) {
            $this->key = null;
            $this->enabled = false;

            return;
        }
        $this->key = (string) hex2bin($hmacKeyHex);
        $this->enabled = true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute the HMAC for a new audit row.
     *
     * Returns '' when the chainer is disabled (no key configured).
     *
     * @param string               $prevHmacBin  Raw bytes of the previous row's HMAC (or '' for the first row)
     * @param array<string, mixed> $canonicalRow The audit row fields (will be sorted + json-encoded)
     *
     * @return string Raw bytes of the new HMAC (32 bytes for SHA-256), or '' if disabled
     */
    public function compute(string $prevHmacBin, array $canonicalRow): string
    {
        if (!$this->enabled || $this->key === null) {
            return '';
        }

        ksort($canonicalRow);
        $canonical = json_encode($canonicalRow, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash_hmac('sha256', $prevHmacBin . $canonical, $this->key, true);
    }
}
