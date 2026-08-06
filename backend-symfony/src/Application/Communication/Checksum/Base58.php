<?php

declare(strict_types=1);

namespace App\Application\Communication\Checksum;

/**
 * Base58 (Bitcoin alphabet) decode — pure PHP big-number, no GMP/bcmath.
 */
final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Decode a Base58 string to its raw bytes, or null on an invalid character.
     */
    public static function decode(string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        $bytes = [0];

        for ($i = 0, $n = \strlen($input); $i < $n; $i++) {
            $carry = strpos(self::ALPHABET, $input[$i]);

            if ($carry === false) {
                return null;
            }

            for ($j = 0, $m = \count($bytes); $j < $m; $j++) {
                $carry += $bytes[$j] * 58;
                $bytes[$j] = $carry & 0xFF;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        // Each leading '1' maps to a leading zero byte.
        for ($i = 0, $n = \strlen($input); $i < $n && $input[$i] === '1'; $i++) {
            $bytes[] = 0;
        }

        return implode('', array_map('chr', array_reverse($bytes)));
    }
}
