<?php

declare(strict_types=1);

namespace App\Application\Communication\Checksum;

/**
 * IBAN structural + ISO 7064 mod-97 check-digit validation.
 *
 * A well-formed but invalid IBAN (random check digits, as the preprod generator
 * emits) is rejected — the format regex alone cannot tell them apart.
 */
final class Iban
{
    public static function isValid(string $iban): bool
    {
        $iban = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        // Country (2 letters) + 2 check digits + up to 30 alphanumerics (max IBAN 34).
        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/', $iban) !== 1) {
            return false;
        }

        // Move the first four chars to the end, map letters to numbers (A=10 … Z=35).
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';

        for ($i = 0, $n = \strlen($rearranged); $i < $n; $i++) {
            $ch = $rearranged[$i];
            $numeric .= ctype_alpha($ch) ? (string) (\ord($ch) - 55) : $ch;
        }

        // Digit-by-digit mod-97: the running remainder stays < 97, so the largest
        // intermediate value is 97*10+9 = 979 — no large integer cast, safe on any
        // PHP int width.
        $remainder = 0;

        for ($i = 0, $n = \strlen($numeric); $i < $n; $i++) {
            $remainder = ($remainder * 10 + (int) $numeric[$i]) % 97;
        }

        return $remainder === 1;
    }
}
