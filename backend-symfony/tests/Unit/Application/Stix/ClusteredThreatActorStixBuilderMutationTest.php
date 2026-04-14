<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ClusteredThreatActorStixBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-killing tests for ClusteredThreatActorStixBuilder.
 *
 * Targets:
 * - Extension definition fields (id, name, version, extension_types)
 * - Threat-actor required fields (type, spec_version, labels, primary_motivation)
 * - Goals mapping from scam types
 * - Weighted goals with >=10% threshold
 * - STIX pattern generation per IOC type
 * - Description string composition
 * - Indicator object fields
 * - Relationship building
 * - Timestamp parsing
 * - first_seen / last_seen conditional inclusion
 * - cluster_type = 'consolidated'
 * - algorithm version string format
 */
final class ClusteredThreatActorStixBuilderMutationTest extends TestCase
{
    private ClusteredThreatActorStixBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ClusteredThreatActorStixBuilder();
    }

    private function baseClusterData(): array
    {
        return [
            'cluster_id' => 'test-cluster-1',
            'stix_id' => 'threat-actor--aaaa-bbbb-cccc-dddd',
            'name' => 'Test Cluster Actor',
            'status' => 'active',
            'conversation_count' => 5,
            'anchor_ioc_count' => 3,
            'sophistication' => 'minimal',
            'primary_scam_types' => ['ADVANCE_FEE_419'],
            'goals' => ['financial-theft'],
            'first_seen' => '2026-01-15T10:00:00Z',
            'last_seen' => '2026-03-20T15:30:00Z',
            'algorithm_version' => '1.0',
            'anchor_ioc_types' => ['iban', 'phone'],
            'attck_techniques' => ['T1566.002'],
            'indicator_data' => [],
            'indicator_stix_ids' => [],
        ];
    }

    // === Extension definitions ===

    public function test_bundle_contains_two_extension_definitions(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDefs = array_filter($objects, fn ($o) => $o['type'] === 'extension-definition');
        $this->assertCount(2, $extDefs);
    }

    public function test_actor_extension_id_exact(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDef = $this->findByType($objects, 'extension-definition');
        $this->assertSame('extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01', $extDef['id']);
    }

    public function test_actor_extension_has_property_extension_type(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDef = $this->findByType($objects, 'extension-definition');
        $this->assertSame(['property-extension'], $extDef['extension_types']);
    }

    public function test_financial_ioc_extension_has_new_sco_type(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDefs = array_values(array_filter($objects, fn ($o) => $o['type'] === 'extension-definition'));
        $financialExt = $extDefs[1];
        $this->assertSame(['new-sco'], $financialExt['extension_types']);
    }

    public function test_extension_spec_version_21(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDef = $this->findByType($objects, 'extension-definition');
        $this->assertSame('2.1', $extDef['spec_version']);
    }

    public function test_actor_extension_version_2_0(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $extDef = $this->findByType($objects, 'extension-definition');
        $this->assertSame('2.0', $extDef['version']);
    }

    // === Threat-actor fields ===

    public function test_threat_actor_type_field(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('threat-actor', $actor['type']);
    }

    public function test_threat_actor_spec_version(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('2.1', $actor['spec_version']);
    }

    public function test_threat_actor_id_from_cluster_data(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('threat-actor--aaaa-bbbb-cccc-dddd', $actor['id']);
    }

    public function test_threat_actor_name(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('Test Cluster Actor', $actor['name']);
    }

    public function test_threat_actor_types_criminal(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame(['criminal'], $actor['threat_actor_types']);
    }

    public function test_threat_actor_motivation(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('personal-gain', $actor['primary_motivation']);
    }

    public function test_threat_actor_labels(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame(['scam', 'cluster'], $actor['labels']);
    }

    public function test_threat_actor_tlp_amber(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame(['marking-definition--f88d31f6-486f-44da-b317-01333bde0b82'], $actor['object_marking_refs']);
    }

    public function test_threat_actor_sophistication(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertSame('minimal', $actor['sophistication']);
    }

    // === Extension fields in threat-actor ===

    public function test_x_scambuster_actor_extension_present(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertArrayHasKey('x_scambuster_actor', $actor['extensions']);
    }

    public function test_cluster_type_is_consolidated(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame('consolidated', $ext['cluster_type']);
    }

    public function test_cluster_id_in_extension(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame('test-cluster-1', $ext['cluster_id']);
    }

    public function test_conversation_count_in_extension(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame(5, $ext['conversation_count']);
    }

    public function test_anchor_ioc_types_in_extension(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame(['iban', 'phone'], $ext['anchor_ioc_types']);
    }

    public function test_algorithm_version_format(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame('realtime-anchor-ioc-clustering-v1.0', $ext['algorithm']);
    }

    public function test_schema_version_2_0(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $ext = $actor['extensions']['x_scambuster_actor'];
        $this->assertSame('2.0', $ext['schema_version']);
    }

    // === Goals mapping ===

    public function test_goals_for_advance_fee_419(): void
    {
        $data = $this->baseClusterData();
        $data['primary_scam_types'] = ['ADVANCE_FEE_419'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertContains('financial-theft', $actor['goals']);
    }

    public function test_goals_for_phishing(): void
    {
        $data = $this->baseClusterData();
        $data['primary_scam_types'] = ['PHISHING'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertContains('credential-theft', $actor['goals']);
    }

    public function test_goals_for_invoice_fraud_has_bec(): void
    {
        $data = $this->baseClusterData();
        $data['primary_scam_types'] = ['INVOICE_FRAUD'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertContains('business-email-compromise', $actor['goals']);
        $this->assertContains('financial-theft', $actor['goals']);
    }

    public function test_goals_default_for_unknown_type(): void
    {
        $data = $this->baseClusterData();
        $data['primary_scam_types'] = ['UNKNOWN_TYPE_XYZ'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertContains('financial-theft', $actor['goals']);
    }

    public function test_goals_deduplicated(): void
    {
        $data = $this->baseClusterData();
        $data['primary_scam_types'] = ['ADVANCE_FEE_419', 'ROMANCE', 'INVESTMENT'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        // All map to financial-theft, should be deduplicated
        $this->assertSame(['financial-theft'], $actor['goals']);
    }

    // === Weighted goals ===

    public function test_weighted_goals_filter_below_10_percent(): void
    {
        $data = $this->baseClusterData();
        $data['weighted_scam_types'] = [
            ['code' => 'PHISHING', 'count' => 90, 'pct' => 90.0],
            ['code' => 'ROMANCE', 'count' => 5, 'pct' => 5.0], // < 10%, excluded
        ];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertContains('credential-theft', $actor['goals']);
        // ROMANCE (financial-theft) should be filtered out since < 10%
        // Unless PHISHING also maps to financial-theft (it doesn't)
        $this->assertNotContains('financial-theft', $actor['goals']);
    }

    // === STIX pattern building ===

    public function test_stix_pattern_iban(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-1', 'type' => 'iban', 'value' => 'FR7612345']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertSame("[x-scambuster:iban = 'FR7612345']", $indicator['pattern']);
    }

    public function test_stix_pattern_phone(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-2', 'type' => 'phone', 'value' => '+33612345678']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertSame("[x-scambuster:phone = '+33612345678']", $indicator['pattern']);
    }

    public function test_stix_pattern_wallet_btc(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-3', 'type' => 'wallet_btc', 'value' => '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertStringContainsString('wallet_btc', $indicator['pattern']);
    }

    public function test_stix_pattern_default_type(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-4', 'type' => 'custom_type', 'value' => 'test_val']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertSame("[x-scambuster:value = 'test_val']", $indicator['pattern']);
    }

    public function test_stix_pattern_escapes_single_quotes(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-5', 'type' => 'iban', 'value' => "val'ue"]];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertStringContainsString("\\'", $indicator['pattern']);
    }

    // === Indicator fields ===

    public function test_indicator_id_format(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'abc-def', 'type' => 'iban', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertSame('indicator--abc-def', $indicator['id']);
    }

    public function test_indicator_has_malicious_activity_label(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-1', 'type' => 'iban', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertContains('malicious-activity', $indicator['indicator_types']);
        $this->assertContains('attribution', $indicator['indicator_types']);
    }

    public function test_indicator_name_contains_type_and_value(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-1', 'type' => 'iban', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertSame('iban: FR76', $indicator['name']);
    }

    public function test_indicator_labels_include_anchor_ioc_and_type(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-1', 'type' => 'iban', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicator = $this->findByType($objects, 'indicator');
        $this->assertContains('anchor-ioc', $indicator['labels']);
        $this->assertContains('iban', $indicator['labels']);
    }

    // === Empty indicator_id or type skipped ===

    public function test_empty_indicator_id_skipped(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => '', 'type' => 'iban', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicators = array_filter($objects, fn ($o) => $o['type'] === 'indicator');
        $this->assertEmpty($indicators);
    }

    public function test_empty_indicator_type_skipped(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [['indicator_id' => 'ind-1', 'type' => '', 'value' => 'FR76']];
        $objects = $this->builder->buildBundle($data);
        $indicators = array_filter($objects, fn ($o) => $o['type'] === 'indicator');
        $this->assertEmpty($indicators);
    }

    // === first_seen / last_seen conditional ===

    public function test_first_seen_present_when_provided(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertArrayHasKey('first_seen', $actor);
    }

    public function test_first_seen_absent_when_empty(): void
    {
        $data = $this->baseClusterData();
        $data['first_seen'] = '';
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertArrayNotHasKey('first_seen', $actor);
    }

    public function test_last_seen_absent_when_same_as_first_seen(): void
    {
        $data = $this->baseClusterData();
        $data['last_seen'] = $data['first_seen'];
        $objects = $this->builder->buildBundle($data);
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertArrayNotHasKey('last_seen', $actor);
    }

    public function test_last_seen_present_when_different_from_first_seen(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertArrayHasKey('last_seen', $actor);
    }

    // === Description composition ===

    public function test_description_contains_conversation_count(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertStringContainsString('5 conversations', $actor['description']);
    }

    public function test_description_contains_ioc_types(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertStringContainsString('iban', $actor['description']);
        $this->assertStringContainsString('phone', $actor['description']);
    }

    public function test_description_contains_date_range(): void
    {
        $objects = $this->builder->buildBundle($this->baseClusterData());
        $actor = $this->findByType($objects, 'threat-actor');
        $this->assertStringContainsString('2026-01-15', $actor['description']);
        $this->assertStringContainsString('2026-03-20', $actor['description']);
    }

    // === buildThreatActorObjects for conversation export ===

    public function test_build_threat_actor_objects_returns_expected_structure(): void
    {
        $result = $this->builder->buildThreatActorObjects($this->baseClusterData(), ['indicator--1', 'indicator--2']);
        $this->assertArrayHasKey('threat_actor', $result);
        $this->assertArrayHasKey('attack_patterns', $result);
        $this->assertArrayHasKey('relationships', $result);
    }

    public function test_build_threat_actor_objects_uses_conversation_indicators(): void
    {
        $convIndicators = ['indicator--conv-1', 'indicator--conv-2'];
        $result = $this->builder->buildThreatActorObjects($this->baseClusterData(), $convIndicators);

        // Relationships should reference conversation indicators (indicates)
        $indicatesRels = array_filter($result['relationships'], fn ($r) => $r['relationship_type'] === 'indicates');
        $sourceRefs = array_column(array_values($indicatesRels), 'source_ref');

        foreach ($convIndicators as $ci) {
            $this->assertContains($ci, $sourceRefs);
        }
    }

    // === Empty attck_techniques ===

    public function test_no_attack_patterns_without_techniques(): void
    {
        $data = $this->baseClusterData();
        $data['attck_techniques'] = [];
        $objects = $this->builder->buildBundle($data);
        $aps = array_filter($objects, fn ($o) => $o['type'] === 'attack-pattern');
        $this->assertEmpty($aps);
    }

    public function test_empty_string_technique_skipped(): void
    {
        $data = $this->baseClusterData();
        $data['attck_techniques'] = [''];
        $objects = $this->builder->buildBundle($data);
        $aps = array_filter($objects, fn ($o) => $o['type'] === 'attack-pattern');
        $this->assertEmpty($aps);
    }

    // === Fallback to indicator_stix_ids when no indicator_data ===

    public function test_fallback_indicator_stix_ids_used_when_no_data(): void
    {
        $data = $this->baseClusterData();
        $data['indicator_data'] = [];
        $data['indicator_stix_ids'] = ['indicator--fallback-1'];
        $objects = $this->builder->buildBundle($data);

        // Should have relationship referencing fallback indicator
        $rels = array_filter($objects, fn ($o) => $o['type'] === 'relationship');
        $sourceRefs = array_column(array_values($rels), 'source_ref');
        $this->assertContains('indicator--fallback-1', $sourceRefs);
    }

    private function findByType(array $objects, string $type): array
    {
        foreach ($objects as $obj) {
            if ($obj['type'] === $type) {
                return $obj;
            }
        }
        $this->fail("Object of type '{$type}' not found");
    }
}
