<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Stix;

use App\Application\Communication\IocExportMapper;
use App\Application\Stix\StixBundleBuilder;
use PHPUnit\Framework\TestCase;

final class StixBundleBuilderTest extends TestCase
{
    private StixBundleBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new StixBundleBuilder(new IocExportMapper());
    }

    public function testBundleHasNoSpecVersion(): void
    {
        $bundle = $this->builder->buildBundle([]);

        $this->assertSame('bundle', $bundle['type']);
        $this->assertStringStartsWith('bundle--', $bundle['id']);
        $this->assertArrayNotHasKey('spec_version', $bundle);
    }

    public function testBundleContainsIdentityAndMarking(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $objects = $bundle['objects'];

        $types = array_column($objects, 'type');
        $this->assertContains('marking-definition', $types);
        $this->assertContains('identity', $types);
        $this->assertContains('report', $types);
    }

    public function testMarkingUsesOpenCtiWellKnownUuid(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER');
        $marking = $this->findObjectByType($bundle, 'marking-definition');

        $this->assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
        $this->assertSame('tlp', $marking['definition_type']);
        $this->assertSame('2.1', $marking['spec_version']);
    }

    public function testIndicatorHasAllRequiredFields(): void
    {
        $iocs = [[
            'type' => 'domain',
            'value' => 'evil.com',
            'value_norm' => 'evil[.]com',
            'first_seen' => '2026-04-03 12:00:00',
            'confidence' => 0.95,
            'score' => ['agg' => 70],
            'extraction_method' => 'llm',
            'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $indicator = $this->findObjectByType($bundle, 'indicator');

        $this->assertNotNull($indicator);
        $this->assertSame('2.1', $indicator['spec_version']);
        $this->assertStringStartsWith('indicator--', $indicator['id']);
        $this->assertArrayHasKey('name', $indicator);
        $this->assertArrayHasKey('pattern', $indicator);
        $this->assertSame('stix', $indicator['pattern_type']);
        $this->assertArrayHasKey('valid_from', $indicator);
        $this->assertArrayHasKey('valid_until', $indicator);
        $this->assertArrayHasKey('confidence', $indicator);
        $this->assertArrayHasKey('created_by_ref', $indicator);
        $this->assertArrayHasKey('object_marking_refs', $indicator);
        $this->assertArrayHasKey('indicator_types', $indicator);

        // Confidence from data
        $this->assertSame(95, $indicator['confidence']);

        // Timestamps have milliseconds
        $this->assertMatchesRegularExpression('/\.\d{3}Z$/', $indicator['valid_from']);
        $this->assertMatchesRegularExpression('/\.\d{3}Z$/', $indicator['valid_until']);
    }

    public function testIndicatorHasOpenCtiExtension(): void
    {
        $iocs = [[
            'type' => 'email',
            'value' => 'scammer@evil.com',
            'value_norm' => 'scammer@evil.com',
            'first_seen' => '2026-04-03 12:00:00',
            'score' => ['agg' => 0],
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $indicator = $this->findObjectByType($bundle, 'indicator');

        $this->assertArrayHasKey('extensions', $indicator);
        $ext = $indicator['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'] ?? null;
        $this->assertNotNull($ext);
        $this->assertSame('Email-Addr', $ext['x_opencti_main_observable_type']);
    }

    public function testRelationshipsGenerated(): void
    {
        $iocs = [
            ['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-04-03'],
            ['type' => 'email', 'value' => 'a@evil.com', 'value_norm' => 'a@evil.com', 'first_seen' => '2026-04-03'],
        ];

        $relationships = [[
            'source_indicator_id' => 'ind-1',
            'target_indicator_id' => 'ind-2',
            'source_type' => 'domain',
            'source_value_norm' => 'evil.com',
            'target_type' => 'email',
            'target_value_norm' => 'a@evil.com',
            'weight' => 2,
        ]];

        $bundle = $this->builder->buildBundle($iocs, $relationships);
        $rel = $this->findObjectByType($bundle, 'relationship');

        $this->assertNotNull($rel);
        $this->assertSame('related-to', $rel['relationship_type']);
        $this->assertStringStartsWith('indicator--', $rel['source_ref']);
        $this->assertStringStartsWith('indicator--', $rel['target_ref']);
        $this->assertArrayHasKey('confidence', $rel);
        $this->assertSame('2.1', $rel['spec_version']);
    }

    public function testValidUntilCalculation(): void
    {
        // IP has 7-day half-life
        $iocs = [[
            'type' => 'ipv4',
            'value' => '203.0.113.42',
            'value_norm' => '203.0.113.42',
            'first_seen' => '2026-04-01 00:00:00',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $indicator = $this->findObjectByType($bundle, 'indicator');

        $this->assertSame('2026-04-01T00:00:00.000Z', $indicator['valid_from']);
        $this->assertSame('2026-04-08T00:00:00.000Z', $indicator['valid_until']); // +7 days
    }

    public function testFileHashPattern(): void
    {
        $iocs = [[
            'type' => 'sha256',
            'value' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
            'value_norm' => 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
            'first_seen' => '2026-04-03',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $indicator = $this->findObjectByType($bundle, 'indicator');

        $this->assertStringContainsString("file:hashes.'SHA-256'", $indicator['pattern']);
    }

    public function testEachIndicatorGetsASightingSdo(): void
    {
        $iocs = [[
            'type' => 'domain',
            'value' => 'evil.com',
            'value_norm' => 'evil.com',
            'first_seen' => '2026-04-03 12:00:00',
            'last_seen' => '2026-04-05 12:00:00',
            'occurrences' => 7,
            'confidence' => 0.95,
            'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $indicator = $this->findObjectByType($bundle, 'indicator');
        $sighting = $this->findObjectByType($bundle, 'sighting');

        $this->assertNotNull($indicator);
        $this->assertNotNull($sighting);
        $this->assertSame('2.1', $sighting['spec_version']);
        $this->assertStringStartsWith('sighting--', $sighting['id']);
        $this->assertSame($indicator['id'], $sighting['sighting_of_ref']);
        $this->assertSame(7, $sighting['count']);
        $this->assertSame(['identity--f431f809-377b-45e0-aa1c-6a4751cae5ff'], $sighting['where_sighted_refs']);
        $this->assertArrayHasKey('first_seen', $sighting);
        $this->assertArrayHasKey('last_seen', $sighting);
        $this->assertTrue($sighting['last_seen'] >= $sighting['first_seen']);
    }

    public function testSightingCountDefaultsToOneWhenNoOccurrences(): void
    {
        $iocs = [[
            'type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com',
            'first_seen' => '2026-04-03 12:00:00', 'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $sighting = $this->findObjectByType($bundle, 'sighting');

        $this->assertNotNull($sighting);
        $this->assertSame(1, $sighting['count']);
    }

    public function testSightingIsReferencedByTheReport(): void
    {
        $iocs = [[
            'type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com',
            'first_seen' => '2026-04-03 12:00:00', 'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $sighting = $this->findObjectByType($bundle, 'sighting');
        $report = $this->findObjectByType($bundle, 'report');

        $this->assertNotNull($sighting);
        $this->assertNotNull($report);
        $this->assertContains($sighting['id'], $report['object_refs']);
    }

    public function testStandardTypeGetsAnScoAndObservedData(): void
    {
        $iocs = [[
            'type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com',
            'first_seen' => '2026-04-03 12:00:00', 'last_seen' => '2026-04-05 12:00:00',
            'occurrences' => 4, 'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $sco = $this->findObjectByType($bundle, 'domain-name');
        $observed = $this->findObjectByType($bundle, 'observed-data');

        $this->assertNotNull($sco);
        $this->assertSame('evil.com', $sco['value']);
        $this->assertStringStartsWith('domain-name--', $sco['id']);

        $this->assertNotNull($observed);
        $this->assertSame('2.1', $observed['spec_version']);
        $this->assertSame(4, $observed['number_observed']);
        $this->assertSame([$sco['id']], $observed['object_refs']);
        $this->assertArrayHasKey('first_observed', $observed);
        $this->assertArrayHasKey('last_observed', $observed);
    }

    public function testFileTypeScoUsesHashes(): void
    {
        $hash = str_repeat('a', 64);
        $iocs = [[
            'type' => 'sha256', 'value' => $hash, 'value_norm' => $hash,
            'first_seen' => '2026-04-03 12:00:00', 'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $sco = $this->findObjectByType($bundle, 'file');

        $this->assertNotNull($sco);
        $this->assertSame(['SHA-256' => $hash], $sco['hashes']);
        $this->assertArrayNotHasKey('value', $sco);
    }

    public function testCustomTypeHasNoObservedDataOrSco(): void
    {
        // IBAN has no standard STIX SCO — it must still get an indicator + sighting,
        // but no observed-data / SCO.
        $iocs = [[
            'type' => 'iban', 'value' => 'DE89370400440532013000', 'value_norm' => 'de89370400440532013000',
            'first_seen' => '2026-04-03 12:00:00', 'scam_type' => 'INVOICE_FRAUD',
        ]];

        $bundle = $this->builder->buildBundle($iocs);

        $this->assertNotNull($this->findObjectByType($bundle, 'indicator'));
        $this->assertNotNull($this->findObjectByType($bundle, 'sighting'));
        $this->assertNull($this->findObjectByType($bundle, 'observed-data'));
    }

    public function testObservedDataIsReferencedByTheReport(): void
    {
        $iocs = [[
            'type' => 'url', 'value' => 'http://evil.com/x', 'value_norm' => 'http://evil.com/x',
            'first_seen' => '2026-04-03 12:00:00', 'scam_type' => 'PHISHING',
        ]];

        $bundle = $this->builder->buildBundle($iocs);
        $observed = $this->findObjectByType($bundle, 'observed-data');
        $report = $this->findObjectByType($bundle, 'report');

        $this->assertNotNull($observed);
        $this->assertNotNull($report);
        $this->assertContains($observed['id'], $report['object_refs']);
    }

    public function testAmberStrictMarkingIsEmitted(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-04-03 12:00:00']];

        $bundle = $this->builder->buildBundle($iocs, [], 'TLP_AMBER_STRICT');

        $markingIds = $this->objectIdsOfType($bundle, 'marking-definition');
        $this->assertContains('marking-definition--939a9414-2ddd-4d32-a0cd-375ea402b003', $markingIds);

        $indicator = $this->findObjectByType($bundle, 'indicator');
        $this->assertNotNull($indicator);
        $this->assertContains('marking-definition--939a9414-2ddd-4d32-a0cd-375ea402b003', $indicator['object_marking_refs']);
    }

    public function testPerIocTlpInheritance(): void
    {
        // Bundle TLP is AMBER, but each IOC carries its own TLP.
        $iocs = [
            ['type' => 'domain', 'value' => 'green.com', 'value_norm' => 'green.com', 'first_seen' => '2026-04-03 12:00:00', 'tlp' => 'GREEN'],
            ['type' => 'domain', 'value' => 'red.com', 'value_norm' => 'red.com', 'first_seen' => '2026-04-03 12:00:00', 'tlp' => 'RED'],
        ];

        $bundle = $this->builder->buildBundle($iocs, [], 'AMBER');

        $green = 'marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da';
        $red = 'marking-definition--5e57c739-391a-4eb3-b6be-7d15ca92d5ed';
        $amber = 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82';

        // Each indicator is marked with its own TLP...
        $indicatorMarkings = [];

        foreach ($bundle['objects'] as $obj) {
            if (($obj['type'] ?? '') === 'indicator') {
                $indicatorMarkings[] = $obj['object_marking_refs'][0];
            }
        }
        $this->assertContains($green, $indicatorMarkings);
        $this->assertContains($red, $indicatorMarkings);

        // ...and the bundle emits a marking-definition for every TLP used (incl. the AMBER bundle level).
        $markingIds = $this->objectIdsOfType($bundle, 'marking-definition');
        $this->assertContains($green, $markingIds);
        $this->assertContains($red, $markingIds);
        $this->assertContains($amber, $markingIds);
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return list<string>
     */
    private function objectIdsOfType(array $bundle, string $type): array
    {
        $ids = [];

        foreach ($bundle['objects'] as $obj) {
            if (($obj['type'] ?? '') === $type && \is_string($obj['id'] ?? null)) {
                $ids[] = $obj['id'];
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findObjectByType(array $bundle, string $type): ?array
    {
        foreach ($bundle['objects'] as $obj) {
            if (($obj['type'] ?? '') === $type) {
                return $obj;
            }
        }

        return null;
    }
}
