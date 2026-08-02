<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\StixProvenance;
use PHPUnit\Framework\TestCase;

final class StixProvenanceTest extends TestCase
{
    public function testNormalisesStoredTlpSpellings(): void
    {
        self::assertSame('AMBER', StixProvenance::normaliseTlp('TLP:amber'));
        self::assertSame('GREEN', StixProvenance::normaliseTlp('tlp_green'));
        self::assertSame('WHITE', StixProvenance::normaliseTlp('  White '));
        self::assertSame('AMBER+STRICT', StixProvenance::normaliseTlp('TLP:AMBER+STRICT'));
    }

    /**
     * Anything unrecognised must fall back to the more restrictive marking, so a
     * bad row cannot silently widen the sharing policy of what we publish.
     */
    public function testUnknownOrEmptyTlpFallsBackToAmber(): void
    {
        self::assertSame('AMBER', StixProvenance::normaliseTlp(null));
        self::assertSame('AMBER', StixProvenance::normaliseTlp(''));
        self::assertSame('AMBER', StixProvenance::normaliseTlp('PURPLE'));
        self::assertSame(StixProvenance::TLP_MARKING['AMBER'], StixProvenance::markingRefFor('nonsense'));
    }

    public function testAppendsIdentityAndMarkingReferencedByObjects(): void
    {
        $objects = [[
            'type' => 'indicator',
            'id' => 'indicator--1',
            'created_by_ref' => StixProvenance::IDENTITY_ID,
            'object_marking_refs' => [StixProvenance::TLP_MARKING['AMBER']],
        ]];

        $result = StixProvenance::withReferencedSdos($objects);
        $byType = self::indexByType($result);

        self::assertCount(1, $byType['identity'] ?? []);
        self::assertCount(1, $byType['marking-definition'] ?? []);
        self::assertSame(StixProvenance::IDENTITY_ID, $byType['identity'][0]['id']);
        self::assertSame('TLP:AMBER', $byType['marking-definition'][0]['name']);
    }

    /**
     * Existing consumers and tests read objects[0] as the first real object;
     * provenance must never displace it.
     */
    public function testKeepsTheFirstRealObjectFirst(): void
    {
        $objects = [[
            'type' => 'indicator',
            'id' => 'indicator--1',
            'created_by_ref' => StixProvenance::IDENTITY_ID,
        ]];

        $result = StixProvenance::withReferencedSdos($objects);

        self::assertSame('indicator', $result[0]['type']);
    }

    public function testDoesNotDuplicateSdosAlreadyPresent(): void
    {
        $objects = [
            ['type' => 'identity', 'id' => StixProvenance::IDENTITY_ID],
            [
                'type' => 'marking-definition',
                'id' => StixProvenance::TLP_MARKING['GREEN'],
            ],
            [
                'type' => 'indicator',
                'id' => 'indicator--1',
                'created_by_ref' => StixProvenance::IDENTITY_ID,
                'object_marking_refs' => [StixProvenance::TLP_MARKING['GREEN']],
            ],
        ];

        $result = StixProvenance::withReferencedSdos($objects);

        self::assertCount(3, $result);
    }

    public function testAddsNothingWhenNoObjectReferencesProvenance(): void
    {
        $objects = [['type' => 'campaign', 'id' => 'campaign--1']];

        self::assertSame($objects, StixProvenance::withReferencedSdos($objects));
    }

    public function testEmptyEnvelopeStaysEmpty(): void
    {
        self::assertSame([], StixProvenance::withReferencedSdos([]));
    }

    /**
     * An unknown marking id must not fabricate a marking-definition; consumers
     * would then receive an SDO we cannot describe.
     */
    public function testUnknownMarkingRefProducesNoSdo(): void
    {
        self::assertNull(StixProvenance::markingSdo('marking-definition--00000000-0000-0000-0000-000000000000'));

        $objects = [[
            'type' => 'indicator',
            'id' => 'indicator--1',
            'object_marking_refs' => ['marking-definition--00000000-0000-0000-0000-000000000000'],
        ]];

        self::assertSame($objects, StixProvenance::withReferencedSdos($objects));
    }

    /**
     * WHITE and CLEAR share one id — the emitted SDO must use the TLP 2.0 name.
     */
    public function testWhiteAndClearShareOneMarkingNamedClear(): void
    {
        $sdo = StixProvenance::markingSdo(StixProvenance::TLP_MARKING['WHITE']);

        self::assertNotNull($sdo);
        self::assertSame('TLP:CLEAR', $sdo['name']);
        self::assertSame(['tlp' => 'clear'], $sdo['definition']);
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function indexByType(array $objects): array
    {
        $byType = [];

        foreach ($objects as $object) {
            $type = \is_string($object['type'] ?? null) ? $object['type'] : '';
            $byType[$type][] = $object;
        }

        return $byType;
    }
}
