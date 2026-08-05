<?php

declare(strict_types=1);

namespace App\Application\Auth;

/**
 * Shared TOTP verification logic (RFC 6238).
 *
 * Used as a legacy fallback when scheb/2fa-bundle's TotpAuthenticatorInterface
 * is not available (e.g. in test environments without the full 2FA stack).
 */
final class TotpVerifier
{
    /**
     * Verify a 6-digit TOTP code against a base32-encoded secret.
     * Allows +/- 1 time window for clock drift.
     */
    public function verify(string $base32Secret, string $code): bool
    {
        $secret = $this->base32Decode($base32Secret);
        $currentCounter = (int) floor(time() / 30);

        for ($i = -1; $i <= 1; $i++) {
            $counter = $currentCounter + $i;
            $generated = $this->generateTotpCode($secret, $counter);

            if (hash_equals($generated, $code)) {
                return true;
            }
        }

        return false;
    }

    public function base32Decode(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32 = strtoupper(rtrim($base32, '='));
        $binary = '';

        foreach (str_split($base32) as $char) {
            $index = strpos($alphabet, $char);

            if ($index === false) {
                continue;
            }
            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $result = '';
        $chunks = str_split($binary, 8);

        foreach ($chunks as $chunk) {
            if (\strlen($chunk) < 8) {
                break;
            }
            $result .= \chr((int) bindec($chunk));
        }

        return $result;
    }

    private function generateTotpCode(string $secret, int $counter): string
    {
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secret, true);

        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }
}
