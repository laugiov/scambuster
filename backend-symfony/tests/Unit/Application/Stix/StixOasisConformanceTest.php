<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\StixObjectDeduplicator;
use App\Application\Stix\ThreatActorStixBuilder;
use App\Application\Stix\TtpAttackPatternBuilder;
use App\Application\Taxii\TaxiiService;
use PHPUnit\Framework\TestCase;

/**
 * STIX 2.1 / OASIS conformance guards for the TAXII feeds.
 */
final class StixOasisConformanceTest extends TestCase
{
    // ── D1: UTC Z timestamps with milliseconds ──────────────────────────────

    /**
     * @dataProvider iso8601Cases
     */
    public function testFormatIso8601IsUtcZWithMilliseconds(string $input, string $expected): void
    {
        $svc = (new \ReflectionClass(TaxiiService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(TaxiiService::class, 'formatIso8601');
        $out = $m->invoke($svc, $input);

        self::assertIsString($out);
        self::assertStringEndsWith('Z', $out, 'timestamp must be UTC-Z, not an offset');
        self::assertStringNotContainsString('+00:00', $out);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $out);

        if ($expected !== '') {
            self::assertSame($expected, $out);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function iso8601Cases(): array
    {
        return [
            'utc offset -> Z'        => ['2026-01-02T03:04:05+00:00', '2026-01-02T03:04:05.000Z'],
            'non-utc converted'      => ['2026-01-02T03:04:05+02:00', '2026-01-02T01:04:05.000Z'],
            'empty -> now (shape)'   => ['', ''],
        ];
    }

    // ── D2: attack-pattern carries created/modified ─────────────────────────

    public function testThreatActorAttackPatternHasCreatedAndModified(): void
    {
        $patterns = (new ThreatActorStixBuilder())->buildAttackPatterns('T1566');

        self::assertNotSame([], $patterns, 'T1566 is a known MITRE technique');
        $ap = $patterns[0];

        self::assertArrayHasKey('created', $ap, 'attack-pattern must carry created (STIX 2.1)');
        self::assertArrayHasKey('modified', $ap, 'attack-pattern must carry modified (STIX 2.1)');
        self::assertMatchesRegularExpression('/Z$/', (string) $ap['created']);
        self::assertMatchesRegularExpression('/Z$/', (string) $ap['modified']);
    }

    // ── D3: stop_time > start_time (or omitted) ─────────────────────────────

    public function testUsesRelationshipOmitsStopTimeForSinglePointSighting(): void
    {
        $objects = (new TtpAttackPatternBuilder())->buildClusterTtpObjects(
            [['code' => 'SB-T001', 'label' => 'Test TTP', 'first_seen' => '2026-01-01T00:00:00.000Z', 'last_seen' => '2026-01-01T00:00:00.000Z', 'count' => 1]],
            'threat-actor--00000000-0000-4000-8000-000000000001',
            'cluster-1',
            '2026-01-02T00:00:00.000Z',
        );

        $uses = $this->firstUsesRelationship($objects);
        self::assertNotNull($uses, 'a uses relationship must be present');
        self::assertArrayHasKey('start_time', $uses);
        self::assertArrayNotHasKey('stop_time', $uses, 'stop_time must be omitted when it would equal start_time');
    }

    public function testUsesRelationshipKeepsStopTimeWhenStrictlyAfter(): void
    {
        $objects = (new TtpAttackPatternBuilder())->buildClusterTtpObjects(
            [['code' => 'SB-T001', 'label' => 'Test TTP', 'first_seen' => '2026-01-01T00:00:00.000Z', 'last_seen' => '2026-01-05T00:00:00.000Z', 'count' => 3]],
            'threat-actor--00000000-0000-4000-8000-000000000001',
            'cluster-1',
            '2026-01-06T00:00:00.000Z',
        );

        $uses = $this->firstUsesRelationship($objects);
        self::assertNotNull($uses);
        self::assertArrayHasKey('stop_time', $uses);
        self::assertGreaterThan((string) $uses['start_time'], (string) $uses['stop_time']);
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return array<string, mixed>|null
     */
    private function firstUsesRelationship(array $objects): ?array
    {
        foreach ($objects as $o) {
            if (($o['type'] ?? '') === 'relationship' && ($o['relationship_type'] ?? '') === 'uses') {
                return $o;
            }
        }

        return null;
    }

    // ── D4: bundle objects unique by id ─────────────────────────────────────

    public function testDedupeByIdKeepsFirstAndPreservesOrder(): void
    {
        $ext = ['type' => 'extension-definition', 'id' => 'extension-definition--aaaa'];
        $in = [
            ['type' => 'threat-actor', 'id' => 'threat-actor--1'],
            $ext,
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--x'],
            $ext, // duplicate from another cluster
            ['type' => 'attack-pattern', 'id' => 'attack-pattern--x'], // shared MITRE AP
            ['type' => 'threat-actor', 'id' => 'threat-actor--2'],
        ];

        $out = StixObjectDeduplicator::dedupeById($in);

        $ids = array_map(static fn (array $o): string => (string) $o['id'], $out);
        self::assertSame(
            ['threat-actor--1', 'extension-definition--aaaa', 'attack-pattern--x', 'threat-actor--2'],
            $ids,
        );
    }

    public function testDedupeByIdPassesThroughEntriesWithoutId(): void
    {
        $in = [
            ['type' => 'marking-definition'], // no id key
            ['type' => 'threat-actor', 'id' => 'threat-actor--1'],
            ['type' => 'threat-actor', 'id' => 'threat-actor--1'],
        ];

        $out = StixObjectDeduplicator::dedupeById($in);

        self::assertCount(2, $out, 'id-less entries pass through; duplicate ids collapse');
    }
}
