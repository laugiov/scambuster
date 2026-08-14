<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ScambusterStixExtensions;
use App\Application\Stix\TtpAttackPatternBuilder;
use App\Domain\Communication\Service\TtpStixIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the TTP STIX object builder: the attack-pattern catalogue
 * (kill_chain_phases, MITRE external references, determinism), the cluster
 * uses-relationships and sightings (start/stop_time, cluster_id extension,
 * collision-free ids, count clamp) and — the hard rule — that no verbatim
 * evidence ever reaches the generated objects.
 */
final class TtpAttackPatternBuilderTest extends TestCase
{
    private const NOW = '2026-07-30T12:00:00.000Z';
    private const ACTOR_ID = 'threat-actor--11111111-1111-4111-8111-111111111111';
    private const CLUSTER_ID = 'dddddddd-0000-4000-8000-0000000000c1';

    private TtpAttackPatternBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TtpAttackPatternBuilder();
    }

    /**
     * @return list<array{code: string, label: string, definition: string, phase: string, external_refs: list<array{source_name: string, external_id: string}>}>
     */
    private function taxonomySeeds(): array
    {
        if (!class_exists(\DoctrineMigrations\Version2026073000000000::class, false)) {
            require_once \dirname(__DIR__, 4) . '/migrations/Version2026073000000000.php';
        }

        $reflection = new \ReflectionClass(\DoctrineMigrations\Version2026073000000000::class);
        /** @var list<array{code: string, label: string, definition: string, phase: string, external_refs: list<array{source_name: string, external_id: string}>}> $seeds */
        $seeds = $reflection->getConstant('SEEDS');

        return $seeds;
    }

    public function testBuildsOneAttackPatternPerTaxonomyEntry(): void
    {
        $patterns = $this->builder->buildAttackPatterns($this->taxonomySeeds());

        self::assertCount(27, $patterns);

        foreach ($patterns as $ap) {
            self::assertSame('attack-pattern', $ap['type']);
            self::assertSame('2.1', $ap['spec_version']);
            self::assertMatchesRegularExpression(
                '/^attack-pattern--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                (string) $ap['id'],
            );
            self::assertArrayHasKey('name', $ap);
            self::assertNotSame('', $ap['name']);
            self::assertArrayHasKey('description', $ap);
            self::assertArrayHasKey('created', $ap);
            self::assertArrayHasKey('modified', $ap);
            self::assertSame(['marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9'], $ap['object_marking_refs']);
        }
    }

    public function testEveryAttackPatternCarriesItsKillChainPhase(): void
    {
        $byCode = [];

        foreach ($this->taxonomySeeds() as $seed) {
            $byCode[$seed['code']] = $seed;
        }

        $patterns = $this->builder->buildAttackPatterns($this->taxonomySeeds());
        $idGen = new TtpStixIdGenerator();
        $byId = [];

        foreach ($patterns as $ap) {
            $byId[(string) $ap['id']] = $ap;
        }

        foreach ($byCode as $code => $seed) {
            $ap = $byId[$idGen->attackPatternId($code)];

            self::assertArrayHasKey('kill_chain_phases', $ap, "TTP {$code} attack-pattern missing kill_chain_phases");
            /** @var list<array<string, string>> $phases */
            $phases = $ap['kill_chain_phases'];
            self::assertCount(1, $phases);
            self::assertSame('scambuster-scam-phases', $phases[0]['kill_chain_name']);
            self::assertSame($seed['phase'], $phases[0]['phase_name'], "TTP {$code} phase mismatch");
        }
    }

    public function testExternalReferencesOnlyWhereMitreRefExists(): void
    {
        $idGen = new TtpStixIdGenerator();
        $patterns = $this->builder->buildAttackPatterns($this->taxonomySeeds());
        $byId = [];

        foreach ($patterns as $ap) {
            $byId[(string) $ap['id']] = $ap;
        }

        // SB-T001 carries a MITRE T1566 reference.
        $t001 = $byId[$idGen->attackPatternId('SB-T001')];
        self::assertArrayHasKey('external_references', $t001);
        /** @var list<array{source_name: string, external_id: string, url: string}> $refs */
        $refs = $t001['external_references'];
        self::assertSame('mitre-attack', $refs[0]['source_name']);
        self::assertSame('T1566', $refs[0]['external_id']);
        self::assertSame('https://attack.mitre.org/techniques/T1566/', $refs[0]['url']);

        // SB-T004 carries a sub-technique — dot becomes slash in the URL.
        $t004 = $byId[$idGen->attackPatternId('SB-T004')];
        /** @var list<array{source_name: string, external_id: string, url: string}> $t004Refs */
        $t004Refs = $t004['external_references'];
        $urls = array_column($t004Refs, 'url');
        self::assertContains('https://attack.mitre.org/techniques/T1566/001/', $urls);
        self::assertContains('https://attack.mitre.org/techniques/T1566/002/', $urls);

        // A technique with no honest equivalent in either framework omits the key
        // entirely. SB-T007 is one of the ten mapped ABSENTE against F3 v1.1 (see
        // docs/standards-track.md); it used to be SB-T006, which now carries an F3
        // reference.
        $t007 = $byId[$idGen->attackPatternId('SB-T007')];
        self::assertArrayNotHasKey('external_references', $t007);
    }

    /**
     * The builder emits any source in its allowlist, not the single hard-coded
     * mitre-attack it started with.
     */
    public function testEmitsMitreF3ReferencesAlongsideAttackReferences(): void
    {
        $patterns = $this->builder->buildAttackPatterns([[
            'code' => 'SB-T001',
            'label' => 'x',
            'definition' => 'x',
            'phase' => 'hook',
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566'],
                ['source_name' => 'mitre-f3', 'external_id' => 'F1020.001'],
            ],
        ]]);

        /** @var list<array{source_name: string, external_id: string}> $refs */
        $refs = $patterns[0]['external_references'];

        self::assertSame(['mitre-attack', 'mitre-f3'], array_column($refs, 'source_name'));
        self::assertSame(['T1566', 'F1020.001'], array_column($refs, 'external_id'));
    }

    /**
     * F3 addresses every technique verbatim under one base, with no trailing slash
     * and the dot kept on sub-techniques. Read from the pinned bundle, where all 123
     * attack-patterns carry that url. The ATT&CK reshaping (dot to path segment,
     * trailing slash) must not leak onto F3 ids.
     */
    /**
     * The url lookup in buildExternalReferences is total: it reads SOURCE_URL_BASES
     * directly, with no fallback. Allowing a source without giving it a base would
     * build a url from an empty string — a malformed reference published to
     * consumers, which is worse than shipping none. Adding a source means verifying
     * its url format first, and this keeps the two constants from drifting apart.
     */
    public function testEveryAllowedSourceHasAUrlBase(): void
    {
        $reflection = new \ReflectionClass(TtpAttackPatternBuilder::class);
        /** @var list<string> $allowed */
        $allowed = $reflection->getConstant('ALLOWED_SOURCE_NAMES');
        /** @var array<string, string> $bases */
        $bases = $reflection->getConstant('SOURCE_URL_BASES');

        foreach ($allowed as $source) {
            self::assertArrayHasKey(
                $source,
                $bases,
                sprintf(
                    "'%s' is an allowed external reference source but has no verified URL base."
                    . ' Verify its canonical url format against its own catalogue and add it to'
                    . ' SOURCE_URL_BASES, or drop it from ALLOWED_SOURCE_NAMES.',
                    $source
                )
            );
        }
    }

    public function testMitreF3ReferencesCarryTheCanonicalCtidUrl(): void
    {
        $patterns = $this->builder->buildAttackPatterns([[
            'code' => 'SB-T001',
            'label' => 'x',
            'definition' => 'x',
            'phase' => 'hook',
            'external_refs' => [
                ['source_name' => 'mitre-f3', 'external_id' => 'F1020'],
                ['source_name' => 'mitre-f3', 'external_id' => 'F1020.001'],
                // An ATT&CK id re-used by F3 still gets the F3 url under mitre-f3.
                ['source_name' => 'mitre-f3', 'external_id' => 'T1598'],
            ],
        ]]);

        /** @var list<array{source_name: string, external_id: string, url: string}> $refs */
        $refs = $patterns[0]['external_references'];

        self::assertCount(3, $refs);
        self::assertSame('https://ctid.mitre.org/fraud/techniques/F1020', $refs[0]['url']);
        self::assertSame('https://ctid.mitre.org/fraud/techniques/F1020.001', $refs[1]['url']);
        self::assertSame('https://ctid.mitre.org/fraud/techniques/T1598', $refs[2]['url']);
    }

    /**
     * F3 references existing ATT&CK techniques where they apply to fraud, so the same
     * external_id under two source names is legitimate. They are different knowledge
     * bases and both references are kept.
     */
    public function testKeepsTheSameExternalIdUnderTwoSourceNames(): void
    {
        $patterns = $this->builder->buildAttackPatterns([[
            'code' => 'SB-T014',
            'label' => 'x',
            'definition' => 'x',
            'phase' => 'payment-request',
            'external_refs' => [
                ['source_name' => 'mitre-attack', 'external_id' => 'T1598'],
                ['source_name' => 'mitre-f3', 'external_id' => 'T1598'],
            ],
        ]]);

        /** @var list<array{source_name: string, external_id: string}> $refs */
        $refs = $patterns[0]['external_references'];

        self::assertCount(2, $refs);
        self::assertSame(['T1598', 'T1598'], array_column($refs, 'external_id'));
        self::assertSame(['mitre-attack', 'mitre-f3'], array_column($refs, 'source_name'));
    }

    /**
     * The allowlist is the guard against a hand-edited or migrated external_refs row
     * publishing a knowledge base this project has never checked against.
     */
    public function testDropsReferencesFromSourcesOutsideTheAllowlist(): void
    {
        $patterns = $this->builder->buildAttackPatterns([[
            'code' => 'SB-T001',
            'label' => 'x',
            'definition' => 'x',
            'phase' => 'hook',
            'external_refs' => [
                ['source_name' => 'disarm', 'external_id' => 'T0086'],
                ['source_name' => 'capec', 'external_id' => 'CAPEC-98'],
                ['source_name' => 'mitre-attack', 'external_id' => 'T1566'],
            ],
        ]]);

        /** @var list<array{source_name: string, external_id: string}> $refs */
        $refs = $patterns[0]['external_references'];

        self::assertSame(['mitre-attack'], array_column($refs, 'source_name'));
    }

    public function testDropsAllowlistedSourcesThatCarryAnEmptyExternalId(): void
    {
        $patterns = $this->builder->buildAttackPatterns([[
            'code' => 'SB-T001',
            'label' => 'x',
            'definition' => 'x',
            'phase' => 'hook',
            'external_refs' => [['source_name' => 'mitre-f3', 'external_id' => '']],
        ]]);

        self::assertArrayNotHasKey('external_references', $patterns[0]);
    }

    public function testAttackPatternIdsAreDeterministic(): void
    {
        $first = $this->builder->buildAttackPatterns($this->taxonomySeeds());
        $second = $this->builder->buildAttackPatterns($this->taxonomySeeds());

        self::assertSame(
            array_column($first, 'id'),
            array_column($second, 'id'),
        );
    }

    public function testNoEvidenceLeaksEvenWhenHandedAnEvidenceField(): void
    {
        // The builder must ignore any evidence-bearing key: taxonomy text only.
        $ttps = [[
            'code' => 'SB-T013',
            'label' => 'Payment instrument designation',
            'definition' => 'Provides concrete payment coordinates.',
            'phase' => 'payment-request',
            'external_refs' => [],
            'count' => 3,
            'first_seen' => '2026-06-01 10:00:00',
            'last_seen' => '2026-06-05 18:00:00',
            'evidence' => 'VERBATIM-SCAMMER-QUOTE-MUST-NOT-LEAK',
        ]];

        $objects = $this->builder->buildClusterTtpObjects($ttps, self::ACTOR_ID, self::CLUSTER_ID, self::NOW);
        $json = json_encode($objects, \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('VERBATIM-SCAMMER-QUOTE-MUST-NOT-LEAK', $json);
    }

    public function testClusterObjectsEmitExtensionDefinitionApRelationshipAndSighting(): void
    {
        $ttps = [[
            'code' => 'SB-T013',
            'label' => 'Payment instrument designation',
            'definition' => 'Provides concrete payment coordinates.',
            'phase' => 'payment-request',
            'external_refs' => [],
            'count' => 4,
            'first_seen' => '2026-06-01 10:00:00',
            'last_seen' => '2026-06-05 18:00:00',
        ]];

        $objects = $this->builder->buildClusterTtpObjects($ttps, self::ACTOR_ID, self::CLUSTER_ID, self::NOW);
        $byType = $this->groupByType($objects);

        self::assertCount(1, $byType['extension-definition']);
        self::assertSame(ScambusterStixExtensions::TTP_SIGHTING_ID, $byType['extension-definition'][0]['id']);
        self::assertCount(1, $byType['attack-pattern']);
        self::assertCount(1, $byType['relationship']);
        self::assertCount(1, $byType['sighting']);

        $apId = (string) $byType['attack-pattern'][0]['id'];

        $rel = $byType['relationship'][0];
        self::assertSame('uses', $rel['relationship_type']);
        self::assertSame(self::ACTOR_ID, $rel['source_ref']);
        self::assertSame($apId, $rel['target_ref']);
        self::assertSame('2026-06-01T10:00:00.000Z', $rel['start_time']);
        self::assertSame('2026-06-05T18:00:00.000Z', $rel['stop_time']);

        $sighting = $byType['sighting'][0];
        self::assertSame($apId, $sighting['sighting_of_ref']);
        self::assertSame(4, $sighting['count']);
        self::assertSame('2026-06-01T10:00:00.000Z', $sighting['first_seen']);
        self::assertSame('2026-06-05T18:00:00.000Z', $sighting['last_seen']);
        self::assertSame(['identity--f431f809-377b-45e0-aa1c-6a4751cae5ff'], $sighting['where_sighted_refs']);

        $ext = $sighting['extensions'][ScambusterStixExtensions::TTP_SIGHTING_ID]['x_scambuster_ttp_sighting'] ?? null;
        self::assertIsArray($ext);
        self::assertSame(self::CLUSTER_ID, $ext['cluster_id']);
        self::assertSame('1.0', $ext['schema_version']);
    }

    public function testSightingCountIsClamped(): void
    {
        $low = $this->builder->buildClusterTtpObjects(
            [['code' => 'SB-T001', 'label' => 'x', 'definition' => 'x', 'phase' => 'hook', 'external_refs' => [], 'count' => 0, 'first_seen' => '2026-06-01 10:00:00', 'last_seen' => '2026-06-01 10:00:00']],
            self::ACTOR_ID,
            self::CLUSTER_ID,
            self::NOW,
        );
        $high = $this->builder->buildClusterTtpObjects(
            [['code' => 'SB-T001', 'label' => 'x', 'definition' => 'x', 'phase' => 'hook', 'external_refs' => [], 'count' => 5_000_000_000, 'first_seen' => '2026-06-01 10:00:00', 'last_seen' => '2026-06-01 10:00:00']],
            self::ACTOR_ID,
            self::CLUSTER_ID,
            self::NOW,
        );

        self::assertSame(1, $this->groupByType($low)['sighting'][0]['count']);
        self::assertSame(999999999, $this->groupByType($high)['sighting'][0]['count']);
    }

    public function testSightingIdsAreCollisionFreeAcrossClustersAndCodes(): void
    {
        $ids = [];

        foreach ([self::CLUSTER_ID, 'dddddddd-0000-4000-8000-0000000000c2'] as $clusterId) {
            foreach (['SB-T001', 'SB-T013'] as $code) {
                $objects = $this->builder->buildClusterTtpObjects(
                    [['code' => $code, 'label' => 'x', 'definition' => 'x', 'phase' => 'hook', 'external_refs' => [], 'count' => 1, 'first_seen' => '2026-06-01 10:00:00', 'last_seen' => '2026-06-01 10:00:00']],
                    self::ACTOR_ID,
                    $clusterId,
                    self::NOW,
                );
                $ids[] = (string) $this->groupByType($objects)['sighting'][0]['id'];
            }
        }

        self::assertCount(4, array_unique($ids), 'Each (cluster, code) pair must produce a distinct sighting id');
    }

    public function testStopTimeOmittedWhenLastNotAfterFirst(): void
    {
        $objects = $this->builder->buildClusterTtpObjects(
            [['code' => 'SB-T001', 'label' => 'x', 'definition' => 'x', 'phase' => 'hook', 'external_refs' => [], 'count' => 1, 'first_seen' => '2026-06-05 18:00:00', 'last_seen' => '2026-06-01 10:00:00']],
            self::ACTOR_ID,
            self::CLUSTER_ID,
            self::NOW,
        );
        $byType = $this->groupByType($objects);

        // STIX 2.1 requires stop_time > start_time when present. When last_seen is
        // not strictly after first_seen, stop_time is omitted (start_time stays).
        self::assertArrayHasKey('start_time', $byType['relationship'][0]);
        self::assertArrayNotHasKey('stop_time', $byType['relationship'][0]);
        // A sighting still clamps last_seen >= first_seen (equal is valid there).
        self::assertSame($byType['sighting'][0]['first_seen'], $byType['sighting'][0]['last_seen']);
    }

    public function testConversationObjectsHaveApAndRelationshipButNoSightingOrExtDef(): void
    {
        $ttps = [[
            'code' => 'SB-T017',
            'label' => 'Urgency deadline pressure',
            'definition' => 'Imposes a hard deadline.',
            'phase' => 'escalation',
            'external_refs' => [],
            'count' => 2,
            'first_seen' => '2026-06-01 10:00:00',
            'last_seen' => '2026-06-02 10:00:00',
        ]];

        $objects = $this->builder->buildConversationTtpObjects($ttps, self::ACTOR_ID, self::NOW);
        $byType = $this->groupByType($objects);

        self::assertCount(1, $byType['attack-pattern']);
        self::assertCount(1, $byType['relationship']);
        self::assertArrayNotHasKey('sighting', $byType);
        self::assertArrayNotHasKey('extension-definition', $byType);

        $rel = $byType['relationship'][0];
        self::assertSame('uses', $rel['relationship_type']);
        self::assertSame('2026-06-01T10:00:00.000Z', $rel['start_time']);
        self::assertSame('2026-06-02T10:00:00.000Z', $rel['stop_time']);
    }

    public function testEmptyTtpsProduceNoObjects(): void
    {
        self::assertSame([], $this->builder->buildClusterTtpObjects([], self::ACTOR_ID, self::CLUSTER_ID, self::NOW));
        self::assertSame([], $this->builder->buildConversationTtpObjects([], self::ACTOR_ID, self::NOW));
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByType(array $objects): array
    {
        $byType = [];

        foreach ($objects as $obj) {
            $type = \is_string($obj['type'] ?? null) ? $obj['type'] : '';
            $byType[$type][] = $obj;
        }

        return $byType;
    }
}
