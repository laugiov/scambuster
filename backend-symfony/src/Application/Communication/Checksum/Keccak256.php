<?php

declare(strict_types=1);

namespace App\Application\Communication\Checksum;

/**
 * Self-contained Keccak-256 (the original Keccak padding, as used by Ethereum —
 * NOT the NIST SHA3-256 padding). Pure PHP, no GMP/bcmath dependency: each 64-bit
 * lane is held as two 32-bit halves so all operations stay within positive ints.
 *
 * Correctness is pinned by canonical vectors in Keccak256Test (empty string, "abc",
 * the fox sentence, the ERC-20 `transfer` selector) and by the official EIP-55
 * address vectors.
 *
 * Note: the only caller (EthereumAddress, a 40-char address) always fits one
 * absorption block (< 136 bytes). The multi-block loop is implemented per spec but
 * is not exercised by any code path here, and no independent multi-block reference
 * was available to pin it — treat multi-block use as unverified until vectored.
 */
final class Keccak256
{
    private const MASK = 0xFFFFFFFF;
    private const RATE_BYTES = 136; // 1088-bit rate (capacity 512), 256-bit output

    /** Round constants as [low32, high32]. */
    private const RC = [
        [0x00000001, 0x00000000], [0x00008082, 0x00000000], [0x0000808A, 0x80000000],
        [0x80008000, 0x80000000], [0x0000808B, 0x00000000], [0x80000001, 0x00000000],
        [0x80008081, 0x80000000], [0x00008009, 0x80000000], [0x0000008A, 0x00000000],
        [0x00000088, 0x00000000], [0x80008009, 0x00000000], [0x8000000A, 0x00000000],
        [0x8000808B, 0x00000000], [0x0000008B, 0x80000000], [0x00008089, 0x80000000],
        [0x00008003, 0x80000000], [0x00008002, 0x80000000], [0x00000080, 0x80000000],
        [0x0000800A, 0x00000000], [0x8000000A, 0x80000000], [0x80008081, 0x80000000],
        [0x00008080, 0x80000000], [0x80000001, 0x00000000], [0x80008008, 0x80000000],
    ];

    /** Rotation offsets r[x][y], flat by lane index x + 5y. */
    private const ROT = [
        0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39,
        41, 45, 15, 21, 8, 18, 2, 61, 56, 14,
    ];

    public static function hashHex(string $message): string
    {
        return bin2hex(self::hash($message));
    }

    public static function hash(string $message): string
    {
        // Keccak (pad10*1 with 0x01 domain): append 0x01, zero-pad, final byte |= 0x80.
        $padLen = self::RATE_BYTES - (\strlen($message) % self::RATE_BYTES);
        $padded = $message . str_repeat("\x00", $padLen);
        $padded[\strlen($message)] = "\x01";
        $padded[\strlen($padded) - 1] = \chr(\ord($padded[\strlen($padded) - 1]) | 0x80);

        // State: 25 lanes, each [low32, high32].
        $s = array_fill(0, 25, [0, 0]);
        $blocks = str_split($padded, self::RATE_BYTES);

        foreach ($blocks as $block) {
            for ($i = 0; $i < 17; $i++) {
                $off = $i * 8;
                $lo = \ord($block[$off]) | (\ord($block[$off + 1]) << 8) | (\ord($block[$off + 2]) << 16) | (\ord($block[$off + 3]) << 24);
                $hi = \ord($block[$off + 4]) | (\ord($block[$off + 5]) << 8) | (\ord($block[$off + 6]) << 16) | (\ord($block[$off + 7]) << 24);
                $s[$i][0] ^= $lo & self::MASK;
                $s[$i][1] ^= $hi & self::MASK;
            }
            $s = self::permute($s);
        }

        // Squeeze the first 32 bytes (4 lanes, little-endian).
        $out = '';

        for ($i = 0; $i < 4; $i++) {
            [$lo, $hi] = $s[$i];
            $out .= \chr($lo & 0xFF) . \chr(($lo >> 8) & 0xFF) . \chr(($lo >> 16) & 0xFF) . \chr(($lo >> 24) & 0xFF);
            $out .= \chr($hi & 0xFF) . \chr(($hi >> 8) & 0xFF) . \chr(($hi >> 16) & 0xFF) . \chr(($hi >> 24) & 0xFF);
        }

        return $out;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $s
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function permute(array $s): array
    {
        for ($round = 0; $round < 24; $round++) {
            // Theta
            $c = [];

            for ($x = 0; $x < 5; $x++) {
                $lo = $s[$x][0] ^ $s[$x + 5][0] ^ $s[$x + 10][0] ^ $s[$x + 15][0] ^ $s[$x + 20][0];
                $hi = $s[$x][1] ^ $s[$x + 5][1] ^ $s[$x + 10][1] ^ $s[$x + 15][1] ^ $s[$x + 20][1];
                $c[$x] = [$lo & self::MASK, $hi & self::MASK];
            }
            $d = [];

            for ($x = 0; $x < 5; $x++) {
                $rot = self::rotl($c[($x + 1) % 5], 1);
                $d[$x] = [($c[($x + 4) % 5][0] ^ $rot[0]) & self::MASK, ($c[($x + 4) % 5][1] ^ $rot[1]) & self::MASK];
            }

            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 25; $y += 5) {
                    $s[$x + $y][0] = ($s[$x + $y][0] ^ $d[$x][0]) & self::MASK;
                    $s[$x + $y][1] = ($s[$x + $y][1] ^ $d[$x][1]) & self::MASK;
                }
            }

            // Rho + Pi
            $b = array_fill(0, 25, [0, 0]);

            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $src = $x + 5 * $y;
                    $dst = $y + 5 * ((2 * $x + 3 * $y) % 5);
                    $b[$dst] = self::rotl($s[$src], self::ROT[$src]);
                }
            }

            // Chi
            for ($y = 0; $y < 25; $y += 5) {
                for ($x = 0; $x < 5; $x++) {
                    $s[$x + $y][0] = ($b[$x + $y][0] ^ ((~$b[(($x + 1) % 5) + $y][0]) & $b[(($x + 2) % 5) + $y][0])) & self::MASK;
                    $s[$x + $y][1] = ($b[$x + $y][1] ^ ((~$b[(($x + 1) % 5) + $y][1]) & $b[(($x + 2) % 5) + $y][1])) & self::MASK;
                }
            }

            // Iota
            $s[0][0] = ($s[0][0] ^ self::RC[$round][0]) & self::MASK;
            $s[0][1] = ($s[0][1] ^ self::RC[$round][1]) & self::MASK;
        }

        return $s;
    }

    /**
     * Rotate a 64-bit lane (held as [low32, high32]) left by n bits.
     *
     * @param array{0: int, 1: int} $lane
     *
     * @return array{0: int, 1: int}
     */
    private static function rotl(array $lane, int $n): array
    {
        [$lo, $hi] = $lane;
        $n %= 64;

        if ($n === 0) {
            return [$lo & self::MASK, $hi & self::MASK];
        }

        if ($n === 32) {
            return [$hi & self::MASK, $lo & self::MASK];
        }

        if ($n < 32) {
            $nlo = (($lo << $n) | ($hi >> (32 - $n))) & self::MASK;
            $nhi = (($hi << $n) | ($lo >> (32 - $n))) & self::MASK;

            return [$nlo, $nhi];
        }

        $m = $n - 32;
        $nlo = (($hi << $m) | ($lo >> (32 - $m))) & self::MASK;
        $nhi = (($lo << $m) | ($hi >> (32 - $m))) & self::MASK;

        return [$nlo, $nhi];
    }
}
