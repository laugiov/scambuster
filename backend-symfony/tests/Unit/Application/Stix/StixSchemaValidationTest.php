<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Stix;

use App\Application\Communication\IocExportMapper;
use App\Application\Stix\StixBundleBuilder;
use App\Application\Stix\ThreatActorStixBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Structural validation of STIX 2.1 output against the OASIS specification.
 *
 * Rather than downloading the full OASIS JSON schema at runtime, this test
 * builds real STIX bundles via StixBundleBuilder and ThreatActorStixBuilder,
 * then asserts the structural invariants that CTI consumers (OpenCTI, MISP,
 * TheHive) rely on:
 *
 * - Every SDO/SRO has type, spec_version "2.1", id matching type--uuid
 * - Required properties per STIX object type
 * - No duplicate IDs in the bundle
 * - Bundle envelope format
 */
final class StixSchemaValidationTest extends TestCase
{
    private StixBundleBuilder $bundleBuilder;
    private ThreatActorStixBuilder $threatActorBuilder;

    protected function setUp(): void
    {
        $this->bundleBuilder = new StixBundleBuilder(new IocExportMapper());
        $this->threatActorBuilder = new ThreatActorStixBuilder();
    }

    /**
     * Build a realistic bundle with multiple IOC types for validation.
     *
     * @return array<string, mixed>
     */
    private function buildRealisticBundle(): array
    {
        $iocs = [
            [
                'type' => 'email',
                'value' => 'scammer@evil-domain.com',
                'value_norm' => 'scammer@evil-domain.com',
                'first_seen' => '2026-04-01 10:00:00',
                'confidence' => 0.92,
                'score' => ['agg' => 75],
                'extraction_method' => 'llm',
                'scam_type' => 'ADVANCE_FEE_419',
            ],
            [
                'type' => 'domain',
                'value' => 'evil-domain.com',
                'value_norm' => 'evil-domain.com',
                'first_seen' => '2026-04-01 10:00:00',
                'confidence' => 0.88,
                'score' => ['agg' => 60],
                'extraction_method' => 'regex',
                'scam_type' => 'ADVANCE_FEE_419',
            ],
            [
                'type' => 'sha256',
                'value' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
                'value_norm' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
                'first_seen' => '2026-04-02 08:30:00',
                'confidence' => 0.99,
                'score' => ['agg' => 90],
                'extraction_method' => 'attachment',
                'scam_type' => 'PHISHING',
            ],
            [
                'type' => 'iban',
                'value' => 'GB82WEST12345698765432',
                'value_norm' => 'gb82west12345698765432',
                'first_seen' => '2026-04-03 14:00:00',
                'confidence' => 0.95,
                'score' => ['agg' => 85],
                'extraction_method' => 'llm',
                'scam_type' => 'INVOICE_FRAUD',
            ],
            [
                'type' => 'ipv4',
                'value' => '203.0.113.42',
                'value_norm' => '203.0.113.42',
                'first_seen' => '2026-04-01 12:00:00',
                'confidence' => 0.80,
                'score' => ['agg' => 50],
                'extraction_method' => 'regex',
                'scam_type' => 'PHISHING',
            ],
        ];

        $relationships = [[
            'source_indicator_id' => 'ind-1',
            'target_indicator_id' => 'ind-2',
            'source_type' => 'email',
            'source_value_norm' => 'scammer@evil-domain.com',
            'target_type' => 'domain',
            'target_value_norm' => 'evil-domain.com',
            'weight' => 3,
        ]];

        return $this->bundleBuilder->buildBundle(
            $iocs,
            $relationships,
            'AMBER',
            'ScamBuster STIX Validation Test Bundle',
            'Test bundle for structural STIX 2.1 validation',
        );
    }

    /**
     * Extract objects array from bundle with type safety.
     *
     * @param array<string, mixed> $bundle
     *
     * @return list<array<string, mixed>>
     */
    private function getObjects(array $bundle): array
    {
        $raw = $bundle['objects'] ?? [];

        if (!\is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $obj) {
            if (\is_array($obj)) {
                /** @var array<string, mixed> $obj */
                $result[] = $obj;
            }
        }

        return $result;
    }

    /**
     * Safely extract a string value from a mixed array.
     */
    private function str(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    // ---------------------------------------------------------------
    // Bundle envelope
    // ---------------------------------------------------------------

    public function testBundleEnvelopeFormat(): void
    {
        $bundle = $this->buildRealisticBundle();

        $this->assertSame('bundle', $bundle['type']);
        $this->assertMatchesRegularExpression(
            '/^bundle--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $this->str($bundle['id']),
            'Bundle ID must match bundle--uuid pattern',
        );
        // STIX 2.1 spec: bundle MUST NOT have spec_version at top level
        $this->assertArrayNotHasKey('spec_version', $bundle);
        $objects = $this->getObjects($bundle);
        $this->assertNotEmpty($objects);
    }

    // ---------------------------------------------------------------
    // Universal SDO/SRO invariants
    // ---------------------------------------------------------------

    public function testEveryObjectHasTypeSpecVersionAndValidId(): void
    {
        $bundle = $this->buildRealisticBundle();
        $objects = $this->getObjects($bundle);

        foreach ($objects as $index => $obj) {
            $this->assertArrayHasKey('type', $obj, "Object at index {$index} missing 'type'");
            $type = $this->str($obj['type']);

            $this->assertArrayHasKey('spec_version', $obj, "{$type} object at index {$index} missing 'spec_version'");
            $this->assertSame('2.1', $obj['spec_version'], "{$type} object at index {$index} spec_version must be '2.1'");

            $this->assertArrayHasKey('id', $obj, "{$type} object at index {$index} missing 'id'");
            $objId = $this->str($obj['id']);
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($type, '/') . '--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $objId,
                "{$type} object ID must match {$type}--uuid pattern, got: {$objId}",
            );
        }
    }

    // ---------------------------------------------------------------
    // No duplicate IDs
    // ---------------------------------------------------------------

    public function testNoDuplicateIdsInBundle(): void
    {
        $bundle = $this->buildRealisticBundle();
        $objects = $this->getObjects($bundle);

        $ids = [];

        foreach ($objects as $obj) {
            $ids[] = $this->str($obj['id'] ?? null);
        }

        $unique = array_unique($ids);

        $this->assertCount(
            \count($unique),
            $ids,
            'Bundle contains duplicate IDs: ' . implode(', ', array_diff_assoc($ids, $unique)),
        );
    }

    // ---------------------------------------------------------------
    // Indicator required fields
    // ---------------------------------------------------------------

    public function testIndicatorObjectsHaveRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $indicators = $this->filterByType($bundle, 'indicator');

        $this->assertNotEmpty($indicators, 'Bundle must contain at least one indicator');

        foreach ($indicators as $indicator) {
            $id = $this->str($indicator['id']);

            $this->assertArrayHasKey('pattern', $indicator, "Indicator {$id} missing 'pattern'");
            $this->assertNotEmpty($indicator['pattern'], "Indicator {$id} pattern must not be empty");

            $this->assertArrayHasKey('pattern_type', $indicator, "Indicator {$id} missing 'pattern_type'");
            $this->assertSame('stix', $indicator['pattern_type'], "Indicator {$id} pattern_type must be 'stix'");

            $this->assertArrayHasKey('valid_from', $indicator, "Indicator {$id} missing 'valid_from'");
            $validFrom = $this->str($indicator['valid_from']);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
                $validFrom,
                "Indicator {$id} valid_from must be a STIX timestamp with milliseconds",
            );

            $this->assertArrayHasKey('created', $indicator, "Indicator {$id} missing 'created'");
            $this->assertArrayHasKey('modified', $indicator, "Indicator {$id} missing 'modified'");
            $this->assertArrayHasKey('created_by_ref', $indicator, "Indicator {$id} missing 'created_by_ref'");
            $this->assertArrayHasKey('object_marking_refs', $indicator, "Indicator {$id} missing 'object_marking_refs'");
        }
    }

    // ---------------------------------------------------------------
    // Relationship required fields
    // ---------------------------------------------------------------

    public function testRelationshipObjectsHaveRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $relationships = $this->filterByType($bundle, 'relationship');

        $this->assertNotEmpty($relationships, 'Bundle must contain at least one relationship');

        foreach ($relationships as $rel) {
            $id = $this->str($rel['id']);

            $this->assertArrayHasKey('relationship_type', $rel, "Relationship {$id} missing 'relationship_type'");
            $this->assertNotEmpty($rel['relationship_type']);

            $this->assertArrayHasKey('source_ref', $rel, "Relationship {$id} missing 'source_ref'");
            $sourceRef = $this->str($rel['source_ref']);
            $this->assertMatchesRegularExpression(
                '/^[a-z-]+--[0-9a-f-]+$/',
                $sourceRef,
                "Relationship {$id} source_ref must be a valid STIX ID",
            );

            $this->assertArrayHasKey('target_ref', $rel, "Relationship {$id} missing 'target_ref'");
            $targetRef = $this->str($rel['target_ref']);
            $this->assertMatchesRegularExpression(
                '/^[a-z-]+--[0-9a-f-]+$/',
                $targetRef,
                "Relationship {$id} target_ref must be a valid STIX ID",
            );
        }
    }

    // ---------------------------------------------------------------
    // Marking-definition required fields
    // ---------------------------------------------------------------

    public function testMarkingDefinitionObjectsHaveRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $markings = $this->filterByType($bundle, 'marking-definition');

        $this->assertNotEmpty($markings, 'Bundle must contain at least one marking-definition');

        foreach ($markings as $marking) {
            $id = $this->str($marking['id']);

            $this->assertArrayHasKey('definition_type', $marking, "Marking {$id} missing 'definition_type'");
            $this->assertSame('tlp', $marking['definition_type']);

            $this->assertArrayHasKey('definition', $marking, "Marking {$id} missing 'definition'");
            $this->assertIsArray($marking['definition']);
            /** @var array<string, mixed> $def */
            $def = $marking['definition'];
            $this->assertArrayHasKey('tlp', $def, "Marking {$id} definition missing 'tlp' key");
        }
    }

    // ---------------------------------------------------------------
    // Threat-actor required fields (via ThreatActorStixBuilder)
    // ---------------------------------------------------------------

    public function testThreatActorObjectHasRequiredFields(): void
    {
        $campaignData = [
            'campaign_id' => 'test-campaign-001',
            'scam_type' => 'ADVANCE_FEE_419',
            'first_seen' => '2026-03-15 08:00:00',
            'last_seen' => '2026-04-01 16:00:00',
            'profile_yaml' => null,
            'tlp' => 'AMBER',
        ];

        $metrics = [
            'conversation_count' => 5,
            'avg_engagement_hours' => 12.5,
            'avg_turns' => 8.0,
            'unique_ioc_type_count' => 4,
            'has_injection_attempts' => false,
        ];

        $threatActor = $this->threatActorBuilder->buildThreatActor($campaignData, null, $metrics);

        $this->assertSame('threat-actor', $threatActor['type']);
        $this->assertSame('2.1', $threatActor['spec_version']);
        $taId = $this->str($threatActor['id']);
        $this->assertMatchesRegularExpression(
            '/^threat-actor--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $taId,
        );

        $this->assertArrayHasKey('name', $threatActor, 'Threat-actor missing name');
        $this->assertNotEmpty($threatActor['name']);

        $this->assertArrayHasKey('threat_actor_types', $threatActor, 'Threat-actor missing threat_actor_types');
        $this->assertIsArray($threatActor['threat_actor_types']);
        /** @var list<string> $taTypes */
        $taTypes = $threatActor['threat_actor_types'];
        $this->assertContains('criminal', $taTypes);

        $this->assertArrayHasKey('created', $threatActor);
        $this->assertArrayHasKey('modified', $threatActor);
        $this->assertArrayHasKey('created_by_ref', $threatActor);
        $this->assertArrayHasKey('object_marking_refs', $threatActor);
    }

    // ---------------------------------------------------------------
    // Extension-definition SDO format
    // ---------------------------------------------------------------

    public function testExtensionDefinitionObjectsHaveRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $extDefs = $this->filterByType($bundle, 'extension-definition');

        $this->assertNotEmpty($extDefs, 'Bundle must contain extension-definition SDOs');

        foreach ($extDefs as $extDef) {
            $id = $this->str($extDef['id']);

            $this->assertSame('2.1', $extDef['spec_version']);
            $this->assertArrayHasKey('name', $extDef, "Extension-definition {$id} missing 'name'");
            $this->assertArrayHasKey('schema', $extDef, "Extension-definition {$id} missing 'schema'");
            $this->assertArrayHasKey('version', $extDef, "Extension-definition {$id} missing 'version'");
            $this->assertArrayHasKey('extension_types', $extDef, "Extension-definition {$id} missing 'extension_types'");
            $this->assertIsArray($extDef['extension_types']);
        }
    }

    // ---------------------------------------------------------------
    // Report object format
    // ---------------------------------------------------------------

    public function testReportObjectHasRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $reports = $this->filterByType($bundle, 'report');

        $this->assertCount(1, $reports, 'Bundle must contain exactly one report');

        $report = $reports[0];
        $this->assertArrayHasKey('name', $report);
        $this->assertArrayHasKey('published', $report);
        $this->assertArrayHasKey('object_refs', $report);
        $this->assertIsArray($report['object_refs']);
        /** @var list<string> $refs */
        $refs = $report['object_refs'];
        $this->assertNotEmpty($refs, 'Report object_refs must not be empty');
    }

    // ---------------------------------------------------------------
    // Identity object format
    // ---------------------------------------------------------------

    public function testIdentityObjectHasRequiredFields(): void
    {
        $bundle = $this->buildRealisticBundle();
        $identities = $this->filterByType($bundle, 'identity');

        $this->assertCount(1, $identities, 'Bundle must contain exactly one identity');

        $identity = $identities[0];
        $this->assertArrayHasKey('name', $identity);
        $this->assertArrayHasKey('identity_class', $identity);
        $this->assertSame('system', $identity['identity_class']);
    }

    // ---------------------------------------------------------------
    // Cross-reference integrity
    // ---------------------------------------------------------------

    public function testObjectMarkingRefsPointToExistingMarkingDefinitions(): void
    {
        $bundle = $this->buildRealisticBundle();
        $objects = $this->getObjects($bundle);
        $markingIds = [];

        foreach ($this->filterByType($bundle, 'marking-definition') as $m) {
            $markingIds[] = $this->str($m['id']);
        }

        foreach ($objects as $obj) {
            if (!isset($obj['object_marking_refs']) || !\is_array($obj['object_marking_refs'])) {
                continue;
            }

            $objId = $this->str($obj['id']);

            /** @var list<mixed> $markingRefs */
            $markingRefs = $obj['object_marking_refs'];

            foreach ($markingRefs as $ref) {
                $refStr = $this->str($ref);
                $this->assertContains(
                    $refStr,
                    $markingIds,
                    "Object {$objId} references unknown marking-definition: {$refStr}",
                );
            }
        }
    }

    // ---------------------------------------------------------------
    // Attack-pattern from ThreatActorStixBuilder
    // ---------------------------------------------------------------

    public function testAttackPatternObjectsHaveRequiredFields(): void
    {
        $patterns = $this->threatActorBuilder->buildAttackPatterns('T1566.002');

        $this->assertNotEmpty($patterns);

        foreach ($patterns as $ap) {
            $this->assertSame('attack-pattern', $ap['type']);
            $this->assertSame('2.1', $ap['spec_version']);
            $apId = $this->str($ap['id']);
            $this->assertMatchesRegularExpression(
                '/^attack-pattern--[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $apId,
            );
            $this->assertArrayHasKey('name', $ap);
            $this->assertArrayHasKey('external_references', $ap);
            $this->assertNotEmpty($ap['external_references']);
        }
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $bundle
     *
     * @return list<array<string, mixed>>
     */
    private function filterByType(array $bundle, string $type): array
    {
        $result = [];

        foreach ($this->getObjects($bundle) as $obj) {
            if (($obj['type'] ?? '') === $type) {
                $result[] = $obj;
            }
        }

        return $result;
    }
}
