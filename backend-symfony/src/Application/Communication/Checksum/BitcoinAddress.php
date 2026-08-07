<?php

declare(strict_types=1);

namespace App\Application\Communication\Checksum;

/**
 * Bitcoin address checksum validation:
 *  - legacy P2PKH/P2SH (1…/3…): Base58Check (double-SHA256 trailer)
 *  - segwit (bc1…): bech32 (v0) / bech32m (v1+, BIP-350) checksum
 *
 * Verifies the checksum only — enough to reject typo'd/fabricated addresses.
 */
final class BitcoinAddress
{
    private const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST = 1;
    private const BECH32M_CONST = 0x2BC830A3;

    /** Accepted human-readable parts (mainnet, testnet). */
    private const HRP = ['bc', 'tb'];

    public static function isValid(string $address): bool
    {
        $address = trim($address);

        if (str_starts_with(strtolower($address), 'bc1') || str_starts_with(strtolower($address), 'tb1')) {
            return self::isValidBech32($address);
        }

        return self::isValidBase58Check($address);
    }

    private static function isValidBase58Check(string $address): bool
    {
        if (preg_match('/^[13][a-km-zA-HJ-NP-Z1-9]{25,39}$/', $address) !== 1) {
            return false;
        }

        $decoded = Base58::decode($address);

        // version(1) + payload(20) + checksum(4) = 25 bytes for P2PKH/P2SH.
        if ($decoded === null || \strlen($decoded) !== 25) {
            return false;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($expected, $checksum);
    }

    private static function isValidBech32(string $address): bool
    {
        // bech32 is case-insensitive but must not be mixed-case.
        if ($address !== strtolower($address) && $address !== strtoupper($address)) {
            return false;
        }
        $address = strtolower($address);

        $pos = strrpos($address, '1');

        if ($pos === false || $pos < 1 || $pos + 7 > \strlen($address)) {
            return false;
        }

        $hrp = substr($address, 0, $pos);

        if (!\in_array($hrp, self::HRP, true)) {
            return false;
        }

        $dataPart = substr($address, $pos + 1);
        $values = [];

        for ($i = 0, $n = \strlen($dataPart); $i < $n; $i++) {
            $v = strpos(self::BECH32_CHARSET, $dataPart[$i]);

            if ($v === false) {
                return false;
            }

            $values[] = $v;
        }

        // First data symbol is the witness version. Per BIP-350 the checksum
        // constant is tied to it: v0 must use bech32, v1+ must use bech32m —
        // accepting either would pass addresses that are invalid by spec.
        if ($values === []) {
            return false;
        }

        $expected = $values[0] === 0 ? self::BECH32_CONST : self::BECH32M_CONST;

        return self::polymod(array_merge(self::hrpExpand($hrp), $values)) === $expected;
    }

    /**
     * @return list<int>
     */
    private static function hrpExpand(string $hrp): array
    {
        $high = [];
        $low = [];

        for ($i = 0, $n = \strlen($hrp); $i < $n; $i++) {
            $high[] = \ord($hrp[$i]) >> 5;
            $low[] = \ord($hrp[$i]) & 31;
        }

        return array_merge($high, [0], $low);
    }

    /**
     * @param list<int> $values
     */
    private static function polymod(array $values): int
    {
        $gen = [0x3B6A57B2, 0x26508E6D, 0x1EA119FA, 0x3D4233DD, 0x2A1462B3];
        $chk = 1;

        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1FFFFFF) << 5) ^ $v;

            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) {
                    $chk ^= $gen[$i];
                }
            }
        }

        return $chk;
    }
}
