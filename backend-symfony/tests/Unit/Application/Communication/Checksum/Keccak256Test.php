<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication\Checksum;

use App\Application\Communication\Checksum\Keccak256;
use PHPUnit\Framework\TestCase;

/**
 * Keccak-256 verified against canonical vectors. EIP-55 (Ethereum address
 * checksums) depends on this being exactly right, so correctness is proven
 * empirically here rather than trusted.
 */
final class Keccak256Test extends TestCase
{
    /**
     * @dataProvider vectors
     */
    public function testHashMatchesCanonicalVectors(string $input, string $expectedHex): void
    {
        self::assertSame($expectedHex, Keccak256::hashHex($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function vectors(): array
    {
        return [
            'empty' => ['', 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470'],
            'abc'   => ['abc', '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45'],
            'fox'   => ['The quick brown fox jumps over the lazy dog', '4d741b6f1eb29cb2a9b9911c82f56fa8d73b04959d3d9d222895df6c0b28aa15'],
        ];
    }

    /**
     * Independent cross-check from the Ethereum ecosystem: the ERC-20
     * `transfer(address,uint256)` function selector — the first four bytes of its
     * Keccak-256 — is the universally-known 0xa9059cbb.
     */
    public function testErc20TransferSelector(): void
    {
        self::assertStringStartsWith('a9059cbb', Keccak256::hashHex('transfer(address,uint256)'));
    }
}
