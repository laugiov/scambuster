<?php

declare(strict_types=1);

namespace App\Application\Communication\Checksum;

/**
 * Ethereum address validation with EIP-55 mixed-case checksum.
 *
 * All-lowercase or all-uppercase addresses are accepted (no checksum encoded).
 * A mixed-case address must satisfy the EIP-55 Keccak-256 checksum, which catches
 * transcription errors in a copied address.
 */
final class EthereumAddress
{
    public static function isValid(string $address): bool
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address) !== 1) {
            return false;
        }

        $hex = substr($address, 2);
        $lower = strtolower($hex);

        // No case information → nothing to check beyond the format.
        if ($hex === $lower || $hex === strtoupper($hex)) {
            return true;
        }

        $hash = Keccak256::hashHex($lower);

        for ($i = 0; $i < 40; $i++) {
            $ch = $hex[$i];

            if (!ctype_alpha($ch)) {
                continue;
            }

            $shouldBeUpper = hexdec($hash[$i]) >= 8;

            if ($shouldBeUpper !== ($ch === strtoupper($ch))) {
                return false;
            }
        }

        return true;
    }
}
