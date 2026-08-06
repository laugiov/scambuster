<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Collapses STIX objects that share the same `id`.
 *
 * STIX object `id`s are globally unique, so a bundle/envelope must never carry two
 * objects with the same id. When a multi-cluster feed is assembled by concatenating
 * per-cluster bundles, shared SDOs — the `extension-definition`s, and MITRE
 * attack-patterns reused across clusters (same deterministic id) — would otherwise
 * appear once per cluster.
 */
final class StixObjectDeduplicator
{
    /**
     * Keep the first object seen for each `id`, preserving order. Entries without a
     * string `id` are passed through unchanged (never merged).
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return list<array<string, mixed>>
     */
    public static function dedupeById(array $objects): array
    {
        $seen = [];
        $out = [];

        foreach ($objects as $object) {
            $id = $object['id'] ?? null;

            if (!\is_string($id) || $id === '') {
                $out[] = $object;

                continue;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $out[] = $object;
        }

        return $out;
    }
}
