<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Domain\Communication\Service\TtpStixIdGenerator;

/**
 * Builds STIX 2.1 objects for scammer-side TTPs: one attack-pattern per taxonomy
 * entry, the "threat-actor uses attack-pattern" relationships and the sightings
 * that quantify how often a cluster exhibited each TTP.
 *
 * The attack-patterns carry ONLY taxonomy text (name/definition from lkp_ttp) and
 * counts/timestamps — never the verbatim evidence stored on ttp_observation. The
 * evidence is strictly internal (DB + UI + audit CSV) and must never reach STIX,
 * TAXII or MISP.
 *
 * These SB-T* attack-patterns coexist with the MITRE scam-type attack-patterns
 * built by ThreatActorStixBuilder: the two catalogues are distinct SDOs (the SB-T*
 * ids are true UUIDv5 from the taxonomy code, the MITRE ones md5-derived) and are
 * never merged.
 */
final class TtpAttackPatternBuilder
{
    private const IDENTITY_ID = 'identity--f431f809-377b-45e0-aa1c-6a4751cae5ff';

    // Attack-patterns model the shared, public TTP catalogue (TLP:CLEAR); the
    // actor-specific usage/sighting layer inherits the AMBER cluster marking.
    private const TLP_WHITE = 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9';
    private const TLP_AMBER = 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82';

    private const KILL_CHAIN_NAME = 'scambuster-scam-phases';

    // Fixed creation stamp for the taxonomy attack-patterns: they are stable
    // catalogue entries (not per-observation), so a constant keeps the SDOs
    // byte-identical across exports and lets OpenCTI dedup them cleanly.
    private const TAXONOMY_STIX_TIMESTAMP = '2026-07-30T00:00:00.000Z';

    /**
     * External reference sources this project publishes. A taxonomy entry may only
     * carry references from a knowledge base whose mapping has been checked
     * per-entry and recorded (docs/standards/f3-mapping.md); anything else is
     * dropped before it reaches a consumer.
     *
     * @var list<string>
     */
    private const ALLOWED_SOURCE_NAMES = ['mitre-attack', 'mitre-f3'];

    /**
     * Canonical URL base per source, for the sources whose format has been verified
     * against the live site. A source absent from this map emits its reference
     * without a URL rather than with a guessed one.
     *
     * @var array<string, string>
     */
    private const SOURCE_URL_BASES = [
        'mitre-attack' => 'https://attack.mitre.org/techniques/',
    ];

    private const EXTENSION_SCHEMA_VERSION = '1.0';

    private TtpStixIdGenerator $idGenerator;

    public function __construct()
    {
        $this->idGenerator = new TtpStixIdGenerator();
    }

    /**
     * One attack-pattern SDO per taxonomy row. Feeding it TtpManager->allActive()
     * (as arrays) yields the full 27-entry catalogue.
     *
     * @param list<array<string, mixed>> $ttpRows Rows with code/label/definition/phase/external_refs
     *
     * @return list<array<string, mixed>>
     */
    public function buildAttackPatterns(array $ttpRows): array
    {
        $patterns = [];

        foreach ($ttpRows as $row) {
            $pattern = $this->buildAttackPattern($row);

            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * Full cluster TTP object set for a bundle: the extension-definition SDO (once,
     * only when there is at least one TTP) followed by, per observed TTP, its
     * attack-pattern, the "uses" relationship (with start/stop_time from the cluster
     * aggregate) and the sighting (count + first/last_seen + cluster_id extension).
     *
     * @param list<array<string, mixed>> $ttps      Observed-TTP aggregates (code/label/definition/phase/external_refs/count/first_seen/last_seen)
     * @param string                     $actorId   STIX id of the threat-actor the TTPs are attributed to
     * @param string                     $clusterId ScamBuster cluster id (rides in the sighting extension)
     * @param string                     $now       Current STIX timestamp (relationship created/modified)
     *
     * @return list<array<string, mixed>>
     */
    public function buildClusterTtpObjects(array $ttps, string $actorId, string $clusterId, string $now): array
    {
        if ($ttps === [] || $actorId === '') {
            return [];
        }

        $objects = [$this->buildExtensionDefinition()];

        foreach ($ttps as $ttp) {
            $pattern = $this->buildAttackPattern($ttp);

            if ($pattern === null) {
                continue;
            }

            /** @var string $apId */
            $apId = $pattern['id'];
            $objects[] = $pattern;
            $objects[] = $this->buildUsesRelationship($actorId, $apId, $ttp, $now);
            $objects[] = $this->buildSighting($apId, $ttp, $clusterId);
        }

        return $objects;
    }

    /**
     * Per-conversation TTP object set for an unattributed conversation: per observed
     * TTP its attack-pattern and the "uses" relationship (start/stop_time from the
     * conversation's own first/last observation). No sighting and no
     * extension-definition — those are cluster-scoped (the sighting carries a
     * cluster id an unattributed conversation does not have).
     *
     * @param list<array<string, mixed>> $ttps    Observed-TTP aggregates for the conversation
     * @param string                     $actorId STIX id of the singleton "Unattributed Scam Actor"
     * @param string                     $now     Current STIX timestamp (relationship created/modified)
     *
     * @return list<array<string, mixed>>
     */
    public function buildConversationTtpObjects(array $ttps, string $actorId, string $now): array
    {
        if ($ttps === [] || $actorId === '') {
            return [];
        }

        $objects = [];

        foreach ($ttps as $ttp) {
            $pattern = $this->buildAttackPattern($ttp);

            if ($pattern === null) {
                continue;
            }

            /** @var string $apId */
            $apId = $pattern['id'];
            $objects[] = $pattern;
            $objects[] = $this->buildUsesRelationship($actorId, $apId, $ttp, $now);
        }

        return $objects;
    }

    /**
     * The extension-definition SDO backing x_scambuster_ttp_sighting.
     *
     * @return array<string, mixed>
     */
    public function buildExtensionDefinition(): array
    {
        return [
            'type' => 'extension-definition',
            'spec_version' => '2.1',
            'id' => ScambusterStixExtensions::TTP_SIGHTING_ID,
            'created_by_ref' => self::IDENTITY_ID,
            'created' => self::TAXONOMY_STIX_TIMESTAMP,
            'modified' => self::TAXONOMY_STIX_TIMESTAMP,
            'name' => 'ScamBuster TTP Sighting Extension',
            'description' => 'Per-cluster attribution on a TTP attack-pattern sighting: the threat-actor cluster id, so sightings of the same attack-pattern by different clusters stay distinguishable in the shared feed.',
            'schema' => 'https://github.com/laugiov/scambuster',
            'version' => self::EXTENSION_SCHEMA_VERSION,
            'extension_types' => ['property-extension'],
        ];
    }

    /**
     * @param array<string, mixed> $ttp
     *
     * @return array<string, mixed>|null Null when the row has no usable code
     */
    private function buildAttackPattern(array $ttp): ?array
    {
        $code = \is_string($ttp['code'] ?? null) ? $ttp['code'] : '';

        if ($code === '') {
            return null;
        }

        $pattern = [
            'type' => 'attack-pattern',
            'spec_version' => '2.1',
            'id' => $this->idGenerator->attackPatternId($code),
            'created' => self::TAXONOMY_STIX_TIMESTAMP,
            'modified' => self::TAXONOMY_STIX_TIMESTAMP,
            'created_by_ref' => self::IDENTITY_ID,
            'name' => \is_string($ttp['label'] ?? null) ? $ttp['label'] : $code,
            'description' => \is_string($ttp['definition'] ?? null) ? $ttp['definition'] : '',
            'kill_chain_phases' => [[
                'kill_chain_name' => self::KILL_CHAIN_NAME,
                'phase_name' => \is_string($ttp['phase'] ?? null) ? $ttp['phase'] : '',
            ]],
            'external_references' => $this->buildExternalReferences($ttp['external_refs'] ?? null),
            'object_marking_refs' => [self::TLP_WHITE],
        ];

        // Drop external_references when the TTP has no verified MITRE reference
        // (absence is deliberate — never emit an empty or fabricated block).
        return array_filter($pattern, static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * External framework references for an attack-pattern, or null when the TTP
     * carries none.
     *
     * A source name is emitted only when it is in {@see ALLOWED_SOURCE_NAMES}. That
     * allowlist is the guard against a hand-edited or migrated `external_refs` row
     * introducing a source this project has not verified: an unknown source is
     * dropped silently rather than published to consumers.
     *
     * A URL is attached only for sources whose canonical URL format has been
     * verified against the live site ({@see SOURCE_URL_BASES}). MITRE F3 references
     * are emitted without one: the canonical technique URL on ctid.mitre.org is not
     * confirmed, and a guessed URL in a shared feed is worse than no URL at all,
     * because consumers follow it.
     *
     * @return list<array{source_name: string, external_id: string, url?: string}>|null
     */
    private function buildExternalReferences(mixed $externalRefs): ?array
    {
        if (!\is_array($externalRefs)) {
            return null;
        }

        $refs = [];

        foreach ($externalRefs as $ref) {
            if (!\is_array($ref)) {
                continue;
            }

            $sourceName = \is_string($ref['source_name'] ?? null) ? $ref['source_name'] : '';
            $externalId = \is_string($ref['external_id'] ?? null) ? $ref['external_id'] : '';

            if ($externalId === '' || !\in_array($sourceName, self::ALLOWED_SOURCE_NAMES, true)) {
                continue;
            }

            $reference = [
                'source_name' => $sourceName,
                'external_id' => $externalId,
            ];

            $urlBase = self::SOURCE_URL_BASES[$sourceName] ?? null;

            if ($urlBase !== null) {
                // Sub-technique ids address as a path segment: T1566.001 -> T1566/001.
                $reference['url'] = $urlBase . str_replace('.', '/', $externalId) . '/';
            }

            $refs[] = $reference;
        }

        return $refs === [] ? null : $refs;
    }

    /**
     * @param array<string, mixed> $ttp
     *
     * @return array<string, mixed>
     */
    private function buildUsesRelationship(string $actorId, string $apId, array $ttp, string $now): array
    {
        $firstSeen = $this->parseTimestamp($ttp['first_seen'] ?? null);
        $lastSeen = $this->parseTimestamp($ttp['last_seen'] ?? null);

        // STIX 2.1 requires stop_time > start_time when present. For a single-point
        // sighting (last_seen == first_seen, or earlier) omit stop_time rather than
        // emit an equal/invalid value; start_time alone is valid.
        if ($firstSeen !== null && $lastSeen !== null && $lastSeen <= $firstSeen) {
            $lastSeen = null;
        }

        $relationship = [
            'type' => 'relationship',
            'spec_version' => '2.1',
            'id' => 'relationship--' . $this->deterministicUuid('uses-' . $actorId . '-' . $apId),
            'created' => $now,
            'modified' => $now,
            'relationship_type' => 'uses',
            'source_ref' => $actorId,
            'target_ref' => $apId,
            'created_by_ref' => self::IDENTITY_ID,
            'start_time' => $firstSeen,
            'stop_time' => $lastSeen,
            'object_marking_refs' => [self::TLP_AMBER],
        ];

        return array_filter($relationship, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $ttp
     *
     * @return array<string, mixed>
     */
    private function buildSighting(string $apId, array $ttp, string $clusterId): array
    {
        $firstSeen = $this->parseTimestamp($ttp['first_seen'] ?? null) ?? self::TAXONOMY_STIX_TIMESTAMP;
        $lastSeen = $this->parseTimestamp($ttp['last_seen'] ?? null) ?? $firstSeen;

        // STIX requires last_seen >= first_seen.
        if ($lastSeen < $firstSeen) {
            $lastSeen = $firstSeen;
        }

        $code = \is_string($ttp['code'] ?? null) ? $ttp['code'] : '';

        return [
            'type' => 'sighting',
            'spec_version' => '2.1',
            'id' => 'sighting--' . $this->deterministicUuid('sighting:ttp:' . $clusterId . ':' . $code),
            'created' => $firstSeen,
            'modified' => $lastSeen,
            'created_by_ref' => self::IDENTITY_ID,
            'first_seen' => $firstSeen,
            'last_seen' => $lastSeen,
            'count' => $this->clampCount($ttp['count'] ?? null),
            'sighting_of_ref' => $apId,
            'where_sighted_refs' => [self::IDENTITY_ID],
            'object_marking_refs' => [self::TLP_AMBER],
            'extensions' => [
                ScambusterStixExtensions::TTP_SIGHTING_ID => ScambusterStixExtensions::wrap('x_scambuster_ttp_sighting', [
                    'cluster_id' => $clusterId,
                    'schema_version' => self::EXTENSION_SCHEMA_VERSION,
                ]),
            ],
        ];
    }

    private function clampCount(mixed $count): int
    {
        $value = \is_numeric($count) ? (int) $count : 1;

        return max(1, min(999999999, $value));
    }

    private function parseTimestamp(mixed $value): ?string
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Exception) {
            return null;
        }
    }

    private function deterministicUuid(string $input): string
    {
        $hash = md5($input);
        $hash[12] = '4';
        $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }
}
