<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ConformanceFixtureBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Spec 005 FR-002 / User Story 2: importing the same bundle twice must create no
 * duplicate objects in a consumer.
 *
 * That property is not something a consumer can be asked to take on trust, and it
 * is not something the export code states — it is a consequence of every object id
 * being derived from the data rather than generated fresh. If a single id came from
 * a random UUIDv4, a second import would land a second copy of that object in every
 * OpenCTI and MISP instance downstream, and nobody would notice until the graph was
 * already wrong.
 *
 * So this test exports the same fixtures twice and asserts the content objects have
 * identical ids. It checks ids rather than whole objects: the bundle envelope
 * carries a "now" timestamp per export, which is correct STIX behaviour and is not
 * what dedup keys on.
 *
 * Two object types are exempt, and the exemption is the honest scope of the claim
 * rather than a gap being papered over:
 *
 * - `bundle` — the envelope. STIX bundles are transient containers, not content;
 *   consumers unpack them and dedup what is inside.
 * - `report` — one per export run. A report SDO describes "what this export
 *   contained at this moment", so two exports genuinely are two reports. Its
 *   object_refs point at the deduplicating content objects.
 *
 * The conformance statement says exactly this. Claiming blanket id stability would
 * be a claim a consumer could disprove in one afternoon.
 */
final class StixExportDeterminismTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function bundleNames(): array
    {
        return [
            'IOC bundle' => ['ioc-bundle.json'],
            'cluster bundle' => ['cluster-bundle.json'],
            'conversation TTP bundle' => ['conversation-ttp-bundle.json'],
        ];
    }

    /**
     * Object types whose id is fresh per export by design. See the class docblock.
     *
     * @var list<string>
     */
    private const PER_EXPORT_TYPES = ['bundle', 'report'];

    /**
     * @dataProvider bundleNames
     */
    public function testContentObjectIdsAreIdenticalAcrossTwoExportRuns(string $bundleName): void
    {
        $first = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];
        $second = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];

        $this->assertSame(
            $this->contentObjectIds($first),
            $this->contentObjectIds($second),
            sprintf(
                'Every content object id in %s must be identical across runs, or a'
                . ' re-import creates duplicates in the consumer instead of deduplicating.',
                $bundleName
            )
        );
    }

    /**
     * Pins the exemption list itself. If a new object type starts generating random
     * ids, this fails and forces the choice to be made deliberately — either the id
     * becomes deterministic, or the type joins the documented exemptions and the
     * conformance statement is updated to say so.
     *
     * @dataProvider bundleNames
     */
    public function testOnlyTheDocumentedTypesCarryAPerExportId(string $bundleName): void
    {
        $first = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];
        $second = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];

        $unstable = [];

        /** @var list<array<string, mixed>> $objects */
        $objects = $first['objects'];
        /** @var list<array<string, mixed>> $others */
        $others = $second['objects'];

        foreach ($objects as $index => $object) {
            if (($object['id'] ?? null) !== ($others[$index]['id'] ?? null)) {
                $unstable[] = \is_string($object['type'] ?? null) ? $object['type'] : 'unknown';
            }
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($unstable), self::PER_EXPORT_TYPES)),
            'An object type outside the documented exemptions generates a fresh id per export.'
            . ' Either derive it from the data, or add it to PER_EXPORT_TYPES and to the'
            . ' conformance statement.'
        );
    }

    /**
     * @dataProvider bundleNames
     */
    public function testEveryObjectCarriesAnId(string $bundleName): void
    {
        $bundle = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];

        /** @var list<array<string, mixed>> $objects */
        $objects = $bundle['objects'];

        $this->assertNotEmpty($objects, 'a fixture bundle with no objects proves nothing');

        foreach ($objects as $index => $object) {
            $this->assertArrayHasKey('id', $object, sprintf('objects[%d] has no id', $index));
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9-]+--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                (string) $object['id'],
                sprintf('objects[%d] id is not a well-formed STIX id', $index)
            );
        }
    }

    /**
     * @dataProvider bundleNames
     */
    public function testObjectIdsAreUniqueWithinABundle(string $bundleName): void
    {
        $ids = $this->objectIds((new ConformanceFixtureBuilder())->buildAll()[$bundleName]);

        $this->assertSame(
            array_values(array_unique($ids)),
            $ids,
            'A bundle must not carry the same object id twice'
        );
    }

    /**
     * Relationship endpoints that deliberately point outside their own bundle,
     * keyed by bundle.
     *
     * The cluster bundle attributes a cluster to the indicators that anchored it,
     * and those indicators are published in the IOC collection rather than
     * duplicated here. A consumer subscribed to both TAXII collections resolves
     * them; a consumer reading the cluster bundle standalone does not.
     *
     * That is a real, documented characteristic of the feed, not a defect — but it
     * is also exactly the kind of thing that goes unnoticed until an integration
     * breaks, so it is enumerated by object type here and stated plainly in the
     * conformance statement.
     *
     * @var array<string, list<string>>
     */
    private const CROSS_COLLECTION_REF_TYPES = [
        'cluster-bundle.json' => ['indicator'],
    ];

    /**
     * A reference that resolves to nothing is not a schema error: a validator
     * reading the file standalone reports it as a warning and a consumer silently
     * drops the relationship. So the set of unresolved references is pinned
     * exactly — a new one fails this test rather than quietly joining the noise.
     *
     * @dataProvider bundleNames
     */
    public function testOnlyDocumentedReferencesResolveOutsideTheBundle(string $bundleName): void
    {
        $bundle = (new ConformanceFixtureBuilder())->buildAll()[$bundleName];

        /** @var list<array<string, mixed>> $objects */
        $objects = $bundle['objects'];
        $ids = array_flip($this->objectIds($bundle));

        $dangling = [];

        foreach ($objects as $object) {
            if (($object['type'] ?? null) !== 'relationship') {
                continue;
            }

            foreach (['source_ref', 'target_ref'] as $endpoint) {
                $ref = $object[$endpoint] ?? null;

                $this->assertIsString(
                    $ref,
                    sprintf('relationship %s has no %s', (string) ($object['id'] ?? '?'), $endpoint)
                );

                if (!isset($ids[$ref])) {
                    // The object type is the part that matters; the uuid is not
                    // stable enough to enumerate.
                    $dangling[] = explode('--', $ref)[0];
                }
            }
        }

        $this->assertSame(
            self::CROSS_COLLECTION_REF_TYPES[$bundleName] ?? [],
            array_values(array_unique($dangling)),
            sprintf(
                'Unresolved reference types in %s changed. Either the reference is a'
                . ' defect and the object belongs in the bundle, or it is a deliberate'
                . ' cross-collection reference and belongs in CROSS_COLLECTION_REF_TYPES'
                . ' and in the conformance statement.',
                $bundleName
            )
        );
    }

    /**
     * The TTP attack-pattern ids are the ones a standards submission would cite, so
     * they must not move between exports of different bundle types either: the same
     * taxonomy code has to be the same object whether it arrived through a cluster
     * bundle or a conversation bundle.
     */
    public function testTheSameTaxonomyCodeYieldsOneAttackPatternAcrossBundleTypes(): void
    {
        $builder = new ConformanceFixtureBuilder();

        $clusterPatterns = $this->attackPatternsByName($builder->buildClusterBundle());
        $conversationPatterns = $this->attackPatternsByName($builder->buildConversationTtpBundle());

        $shared = array_intersect_key($clusterPatterns, $conversationPatterns);

        $this->assertNotEmpty($shared, 'the two fixtures must share at least one TTP for this to prove anything');

        foreach ($shared as $name => $id) {
            $this->assertSame(
                $id,
                $conversationPatterns[$name],
                sprintf('"%s" must be the same attack-pattern object in both bundle types', $name)
            );
        }
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return list<string>
     */
    private function objectIds(array $bundle): array
    {
        /** @var list<array<string, mixed>> $objects */
        $objects = $bundle['objects'];
        $ids = [];

        foreach ($objects as $object) {
            $ids[] = \is_string($object['id'] ?? null) ? $object['id'] : '';
        }

        return $ids;
    }

    /**
     * Object ids excluding the types that are fresh per export by design.
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<string>
     */
    private function contentObjectIds(array $bundle): array
    {
        /** @var list<array<string, mixed>> $objects */
        $objects = $bundle['objects'];
        $ids = [];

        foreach ($objects as $object) {
            $type = \is_string($object['type'] ?? null) ? $object['type'] : '';

            if (\in_array($type, self::PER_EXPORT_TYPES, true)) {
                continue;
            }

            $ids[] = \is_string($object['id'] ?? null) ? $object['id'] : '';
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, string>
     */
    private function attackPatternsByName(array $bundle): array
    {
        /** @var list<array<string, mixed>> $objects */
        $objects = $bundle['objects'];
        $patterns = [];

        foreach ($objects as $object) {
            if (($object['type'] ?? null) !== 'attack-pattern') {
                continue;
            }

            $name = \is_string($object['name'] ?? null) ? $object['name'] : '';
            $id = \is_string($object['id'] ?? null) ? $object['id'] : '';

            if ($name !== '' && $id !== '') {
                $patterns[$name] = $id;
            }
        }

        return $patterns;
    }
}
