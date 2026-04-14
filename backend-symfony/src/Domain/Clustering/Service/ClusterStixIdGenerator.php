<?php

declare(strict_types=1);

namespace App\Domain\Clustering\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Generates a deterministic STIX ID for a threat-actor cluster.
 *
 * The ID is a UUID v5 derived from the sorted, deduplicated set of normalized
 * anchor IOC values. This guarantees:
 * - Same set of IOCs → same UUID (determinism)
 * - Different order → same UUID (sorted before hashing)
 * - Duplicate values → same UUID (deduplicated)
 *
 * The UUID is calculated ONCE at cluster creation and never recalculated.
 * When a new conversation joins the cluster, the stix_id does not change —
 * only the `modified` timestamp updates. This ensures idempotent imports
 * in OpenCTI (no duplicates even after 10 re-imports).
 *
 * @see https://docs.oasis-open.org/cti/stix/v2.1/os/stix-v2.1-os.html#_64yvzeku5a5c
 */
final class ClusterStixIdGenerator
{
    /** UUID namespace for URL (standard RFC 4122) */
    private const NAMESPACE_URL = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    /**
     * Generate a deterministic STIX threat-actor ID from anchor IOC values.
     *
     * @param array<string> $anchorNormalizedValues Normalized IOC values (output of NormalizedIocValue::normalize)
     *
     * @throws \InvalidArgumentException If the input array is empty
     *
     * @return string Format: "threat-actor--{uuid-v5}"
     *
     * Time complexity: O(N log N) where N = number of anchor values (sort step)
     */
    public function generate(array $anchorNormalizedValues): string
    {
        if ($anchorNormalizedValues === []) {
            throw new \InvalidArgumentException('Cannot generate cluster STIX ID from empty anchor IOC set');
        }

        // Deduplicate + sort → deterministic regardless of insertion order
        $unique = array_unique($anchorNormalizedValues);
        sort($unique);

        // Seed with algorithm version for forward compatibility
        $seed = 'scambuster:cluster:v1:' . implode('|', $unique);

        // UUID v5 in the URL namespace (standard)
        $namespace = Uuid::fromString(self::NAMESPACE_URL);
        $uuid = Uuid::v5($namespace, $seed);

        return 'threat-actor--' . $uuid->toRfc4122();
    }
}
