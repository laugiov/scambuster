<?php

declare(strict_types=1);

namespace App\Domain\Communication\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Generates a deterministic STIX ID for a TTP attack-pattern.
 *
 * The ID is a UUID v5 derived from the TTP code. This guarantees:
 * - Same code -> same UUID (determinism)
 * - The attack-pattern SDO is created once and reused across every export
 *   (never one per observation), so re-imports into OpenCTI/MISP dedup cleanly.
 *
 * @see https://docs.oasis-open.org/cti/stix/v2.1/os/stix-v2.1-os.html#_64yvzeku5a5c
 */
final class TtpStixIdGenerator
{
    /** UUID namespace for URL (standard RFC 4122) */
    private const NAMESPACE_URL = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Generate a deterministic STIX attack-pattern ID from a TTP code.
     *
     * @throws \InvalidArgumentException If the code is empty
     *
     * @return string Format: "attack-pattern--{uuid-v5}"
     */
    public function attackPatternId(string $ttpCode): string
    {
        if ($ttpCode === '') {
            throw new \InvalidArgumentException('Cannot generate a TTP STIX ID from an empty code');
        }

        // Seed with the taxonomy version for forward compatibility: a future
        // taxonomy revision can mint fresh ids without colliding with v1.
        $seed = 'scambuster:ttp:v1:' . $ttpCode;

        $namespace = Uuid::fromString(self::NAMESPACE_URL);
        $uuid = Uuid::v5($namespace, $seed);

        return 'attack-pattern--' . $uuid->toRfc4122();
    }
}
