<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Communication\IocExportMapper;
use App\Domain\Communication\TtpTaxonomySeed;
use Symfony\Component\Uid\Uuid;

/**
 * Builds the three exported bundle types from fixed, in-repository fixture data,
 * so an external STIX validator can check them in CI without a database
 * (Spec 005 FR-001).
 *
 * The fixtures are deliberately adversarial towards this project's own output. They
 * exercise the parts most likely to trip a third-party validator, because those are
 * the parts nobody else has checked:
 *
 * - the custom property extension on sightings, which is this project's own
 *   contribution to the format and therefore the least battle-tested thing it emits;
 * - external references under two source names, one with a URL and one without;
 * - a single-point sighting, where STIX forbids `stop_time <= start_time` and the
 *   builder has to omit the field rather than emit an equal value;
 * - IOC types that map to SCOs and IOC types that do not;
 * - mixed TLP markings inside one bundle.
 *
 * The data itself is invented. No production row, and no verbatim scammer text, is
 * in this file or in anything it produces (Constitution III) — the fixtures use
 * obviously-synthetic values for exactly that reason.
 */
final class ConformanceFixtureBuilder
{
    /** Bundle ids are fixed so two runs produce byte-identical files (FR-002). */
    private const IOC_BUNDLE_ID = 'bundle--1a2b3c4d-0000-4000-8000-000000000001';
    private const CLUSTER_BUNDLE_ID = 'bundle--1a2b3c4d-0000-4000-8000-000000000002';
    private const CONVERSATION_BUNDLE_ID = 'bundle--1a2b3c4d-0000-4000-8000-000000000003';

    private const CLUSTER_ID = 'cccccccc-0000-4000-8000-00000000c001';
    private const FIXTURE_TIMESTAMP = '2026-07-30T12:00:00.000Z';

    public function __construct(
        private readonly StixBundleBuilder $bundleBuilder = new StixBundleBuilder(new IocExportMapper()),
        private readonly ClusteredThreatActorStixBuilder $clusterBuilder = new ClusteredThreatActorStixBuilder(),
        private readonly TtpAttackPatternBuilder $ttpBuilder = new TtpAttackPatternBuilder(),
    ) {
    }

    /**
     * Every bundle type, keyed by the file name it is written to.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildAll(): array
    {
        return [
            'ioc-bundle.json' => $this->buildIocBundle(),
            'cluster-bundle.json' => $this->buildClusterBundle(),
            'conversation-ttp-bundle.json' => $this->buildConversationTtpBundle(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildIocBundle(): array
    {
        $bundle = $this->bundleBuilder->buildBundle(
            $this->iocFixtures(),
            $this->relationshipFixtures(),
            'AMBER',
            'ScamBuster Conformance Fixture — IOC Export',
            'Synthetic fixture data used to validate the IOC bundle against an external STIX 2.1 validator. Contains no production data.',
        );

        return $this->freeze($bundle, self::IOC_BUNDLE_ID);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClusterBundle(): array
    {
        $objects = $this->clusterBuilder->buildBundle($this->clusterFixture());

        return $this->freeze([
            'type' => 'bundle',
            'id' => self::CLUSTER_BUNDLE_ID,
            'objects' => $objects,
        ], self::CLUSTER_BUNDLE_ID);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConversationTtpBundle(): array
    {
        $actorId = 'threat-actor--' . Uuid::v5(
            Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            'scambuster:unattributed-actor'
        )->toRfc4122();

        $objects = $this->ttpBuilder->buildConversationTtpObjects(
            $this->ttpAggregates(),
            $actorId,
            self::FIXTURE_TIMESTAMP,
        );

        // An unattributed conversation bundle still needs the actor the "uses"
        // relationships point at, or every relationship dangles and a validator
        // reading the bundle standalone reports unresolved references.
        array_unshift($objects, [
            'type' => 'threat-actor',
            'spec_version' => '2.1',
            'id' => $actorId,
            'created' => self::FIXTURE_TIMESTAMP,
            'modified' => self::FIXTURE_TIMESTAMP,
            'name' => 'Unattributed Scam Actor',
            'description' => 'Placeholder actor for conversations not attributed to a cluster.',
            'threat_actor_types' => ['criminal-financial'],
        ]);

        return $this->freeze([
            'type' => 'bundle',
            'id' => self::CONVERSATION_BUNDLE_ID,
            'objects' => $objects,
        ], self::CONVERSATION_BUNDLE_ID);
    }

    /**
     * Replace the runtime-generated bundle id and every "now" timestamp with fixed
     * values.
     *
     * The exporters stamp bundles with a random id and the current time, which is
     * correct in production and useless in a fixture: it would make every
     * regeneration a diff. Object ids are untouched — those are already
     * deterministic, and asserting that is the point of the determinism test.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function freeze(array $bundle, string $bundleId): array
    {
        $bundle['id'] = $bundleId;

        $objects = \is_array($bundle['objects'] ?? null) ? $bundle['objects'] : [];
        $frozen = [];

        foreach ($objects as $object) {
            if (!\is_array($object)) {
                continue;
            }

            foreach (['created', 'modified', 'valid_from'] as $field) {
                if (isset($object[$field]) && $this->isRecentTimestamp($object[$field])) {
                    $object[$field] = self::FIXTURE_TIMESTAMP;
                }
            }

            $frozen[] = $object;
        }

        $bundle['objects'] = $frozen;

        return $bundle;
    }

    /**
     * Whether a timestamp was produced by "now" rather than carried from fixture
     * data. Fixture timestamps are all in 2026-06 or earlier; anything at or after
     * the fixture stamp is a runtime clock reading.
     */
    private function isRecentTimestamp(mixed $value): bool
    {
        return \is_string($value) && $value >= self::FIXTURE_TIMESTAMP;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function iocFixtures(): array
    {
        return [
            [
                'indicator_id' => '11111111-0000-4000-8000-000000000001',
                'type' => 'email',
                'value' => 'fixture.beneficiary@example.invalid',
                'value_norm' => 'fixture.beneficiary@example.invalid',
                'first_seen' => '2026-06-01 09:00:00',
                'last_seen' => '2026-06-03 17:30:00',
                'confidence' => 85,
                'score' => 70,
                'extraction_method' => 'llm',
                'occurrence_count' => 4,
                'tlp' => 'AMBER',
            ],
            [
                'indicator_id' => '11111111-0000-4000-8000-000000000002',
                'type' => 'url',
                'value' => 'https://portal.example.invalid/verify',
                'value_norm' => 'https://portal.example.invalid/verify',
                'first_seen' => '2026-06-02 11:15:00',
                'last_seen' => '2026-06-02 11:15:00',
                'confidence' => 90,
                'score' => 80,
                'extraction_method' => 'regex',
                'occurrence_count' => 1,
                'tlp' => 'GREEN',
            ],
            [
                // IBAN has no standard STIX SCO: it exercises the indicator-only path.
                'indicator_id' => '11111111-0000-4000-8000-000000000003',
                'type' => 'iban',
                'value' => 'DE89370400440532013000',
                'value_norm' => 'DE89370400440532013000',
                'first_seen' => '2026-06-04 08:00:00',
                'last_seen' => '2026-06-05 12:00:00',
                'confidence' => 95,
                'score' => 90,
                'extraction_method' => 'regex',
                'occurrence_count' => 2,
                'tlp' => 'AMBER',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationshipFixtures(): array
    {
        // The builder derives both endpoint refs from the indicator's type and
        // normalised value, not from its database id — that is what makes the ref
        // match the indicator the bundle actually carries. Omitting these fields
        // produces a relationship pointing at an indicator that is not in the
        // bundle, so the fixture supplies them exactly as the endpoints do.
        return [[
            'source_indicator_id' => '11111111-0000-4000-8000-000000000001',
            'source_type' => 'email',
            'source_value_norm' => 'fixture.beneficiary@example.invalid',
            'target_indicator_id' => '11111111-0000-4000-8000-000000000003',
            'target_type' => 'iban',
            'target_value_norm' => 'DE89370400440532013000',
            'weight' => 3,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function clusterFixture(): array
    {
        return [
            'cluster_id' => self::CLUSTER_ID,
            // The exporter reads the actor's STIX id and display name off the
            // cluster row rather than deriving them, so the fixture has to carry
            // both: without them the threat-actor ships with an empty id and every
            // relationship in the bundle dangles.
            'stix_id' => 'threat-actor--cccccccc-0000-4000-8000-00000000c001',
            'name' => 'Conformance Fixture Cluster',
            'status' => 'active',
            'conversation_count' => 7,
            'anchor_ioc_count' => 3,
            'sophistication' => 'intermediate',
            'primary_scam_types' => ['ADVANCE_FEE'],
            'goals' => ['financial-theft'],
            'first_seen' => '2026-06-01 09:00:00',
            'last_seen' => '2026-06-05 12:00:00',
            'algorithm_version' => '1.0',
            'anchor_ioc_types' => ['email', 'iban'],
            'attck_techniques' => ['T1566'],
            'indicator_stix_ids' => ['indicator--11111111-0000-4000-8000-000000000001'],
            'ttps' => $this->ttpAggregates(),
        ];
    }

    /**
     * Observed-TTP aggregates, drawn from the real taxonomy so the fixture cannot
     * drift from the codes the platform actually emits.
     *
     * The three entries are chosen for what they exercise, not at random: one with
     * ATT&CK references, one with none, and one whose last_seen equals its
     * first_seen — the single-point case where STIX forbids an equal stop_time and
     * the builder must omit the field.
     *
     * @return list<array<string, mixed>>
     */
    private function ttpAggregates(): array
    {
        /** @var array<string, array<string, mixed>> $byCode */
        $byCode = [];

        foreach (TtpTaxonomySeed::ENTRIES as $entry) {
            $byCode[$entry['code']] = $entry;
        }

        $windows = [
            'SB-T001' => ['count' => 6, 'first_seen' => '2026-06-01 09:00:00', 'last_seen' => '2026-06-05 12:00:00'],
            'SB-T012' => ['count' => 3, 'first_seen' => '2026-06-02 10:00:00', 'last_seen' => '2026-06-04 15:00:00'],
            // Single-point sighting: last_seen == first_seen.
            'SB-T009' => ['count' => 1, 'first_seen' => '2026-06-03 14:00:00', 'last_seen' => '2026-06-03 14:00:00'],
        ];

        $aggregates = [];

        foreach ($windows as $code => $window) {
            $entry = $byCode[$code];

            $aggregates[] = [
                'code' => $entry['code'],
                'label' => $entry['label'],
                'definition' => $entry['definition'],
                'phase' => $entry['phase'],
                'external_refs' => $entry['external_refs'],
                'count' => $window['count'],
                'first_seen' => $window['first_seen'],
                'last_seen' => $window['last_seen'],
            ];
        }

        return $aggregates;
    }
}
