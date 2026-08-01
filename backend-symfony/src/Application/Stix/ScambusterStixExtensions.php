<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Canonical STIX 2.1 extension-definition ids for ScamBuster's custom extensions,
 * plus the helper that wraps a payload as a conformant property-extension entry.
 *
 * STIX 2.1 requires custom extensions to live under `extensions` keyed by an
 * `extension-definition--<uuid>` id (a bare `x_...` key is rejected by strict
 * validators). Every emitter references these ids so the keying is identical and
 * the ext-definition SDOs in the bundle are actually referenced (not orphaned).
 */
final class ScambusterStixExtensions
{
    /** x_scambuster_context — per-indicator structural + semantic IOC context. */
    public const CONTEXT_ID = 'extension-definition--b2a37c23-41d7-4e2f-9c8a-1a5f6d3e8b90';

    /** x_scambuster_actor — threat-actor enrichment (campaign_id, style_dna, …). */
    public const ACTOR_ID = 'extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01';

    /** x_scambuster_actor_psych — the persisted per-actor psychological profile. */
    public const PSYCH_ID = 'extension-definition--e5d6a967-8410-4c5d-8f0b-4e8c9a1b2c34';

    /**
     * x_scambuster_ttp_sighting — per-cluster attribution on a TTP attack-pattern
     * sighting. A sighting cannot carry the cluster in sighting_of_ref (the
     * attack-pattern) nor where_sighted_refs (identity only), so N sightings of
     * the same attack-pattern by different clusters would be indistinguishable in
     * the shared feed; the cluster id rides in this property-extension instead.
     */
    public const TTP_SIGHTING_ID = 'extension-definition--01e32cd7-1bf4-4772-9f0c-50a4863c6a23';

    /**
     * Wrap a custom-extension payload as a STIX 2.1 property-extension entry.
     * The caller keys it under the matching extension-definition id.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function wrap(string $name, array $payload): array
    {
        return [
            'extension_type' => 'property-extension',
            $name => $payload,
        ];
    }
}
