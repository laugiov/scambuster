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
