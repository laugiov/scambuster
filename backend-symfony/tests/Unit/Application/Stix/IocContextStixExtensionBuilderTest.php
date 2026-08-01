<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\IocContextStixExtensionBuilder;
use PHPUnit\Framework\TestCase;

final class IocContextStixExtensionBuilderTest extends TestCase
{
    public function testPendingContextReturnsNull(): void
    {
        $row = ['enrichment_status' => 'pending'];
        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNull($result);
    }

    public function testSkippedContextReturnsNull(): void
    {
        $row = ['enrichment_status' => 'skipped'];
        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNull($result);
    }

    public function testEmptyArrayReturnsNull(): void
    {
        $result = IocContextStixExtensionBuilder::build([]);

        self::assertNull($result);
    }

    public function testStructuralContextReturnsStructuralFieldsOnly(): void
    {
        $row = [
            'enrichment_status' => 'structural',
            'scam_type_code' => 'ADVANCE_FEE_419',
            'scam_type_attck' => 'T1566.001',
            'scam_type_misp' => 'misp-galaxy:scam-type="advance-fee"',
            'persona_code' => 'elderly_person',
            'persona_label' => 'Elderly retiree, low tech literacy',
            'extraction_method' => 'regex',
            'revelation_turn' => 7,
            'revelation_turn_ratio' => 0.35,
            'total_turns' => 20,
            'engagement_hours' => 48.7,
            'co_revealed_types' => '{iban,phone}',
            'co_revealed_count' => 2,
            'stimulus_msg_id' => '11111111-1111-4111-8111-111111111111',
            'reward_value' => 0.42,
            'campaign_id' => '22222222-2222-4222-8222-222222222222',
            'semantic_role' => null,
            'stimulus_type' => null,
            'urgency_score' => null,
            'context_excerpt' => null,
            'enrichment_confidence' => null,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        self::assertSame('1.1', $result['schema_version']);
        self::assertSame('structural', $result['enrichment_status']);
        self::assertSame('ADVANCE_FEE_419', $result['scam_type']);
        self::assertSame('T1566.001', $result['attck_technique']);
        self::assertSame('elderly_person', $result['persona_code']);
        self::assertSame(7, $result['revelation_turn']);
        self::assertSame(0.35, $result['revelation_turn_ratio']);
        self::assertSame(20, $result['total_turns']);
        self::assertSame(48.7, $result['engagement_hours']);
        self::assertSame(['iban', 'phone'], $result['co_revealed_ioc_types']);

        // structural additions surfaced even when not enriched
        self::assertSame('misp-galaxy:scam-type="advance-fee"', $result['misp_taxonomy']);
        self::assertSame('Elderly retiree, low tech literacy', $result['persona_label']);
        self::assertSame(2, $result['co_revealed_count']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $result['stimulus_msg_id']);
        self::assertSame(0.42, $result['reward_value']);
        self::assertSame('22222222-2222-4222-8222-222222222222', $result['campaign_id']);

        // No semantic / LLM-only fields
        self::assertArrayNotHasKey('semantic_role', $result);
        self::assertArrayNotHasKey('stimulus_type', $result);
        self::assertArrayNotHasKey('urgency_score', $result);
        self::assertArrayNotHasKey('context_excerpt', $result);
        self::assertArrayNotHasKey('enrichment_confidence', $result);
        self::assertArrayNotHasKey('enrichment_model', $result);
        self::assertArrayNotHasKey('hesitation_detected', $result);
        self::assertArrayNotHasKey('language_switch', $result);
    }

    public function testEnrichedContextReturnsAllFields(): void
    {
        $row = [
            'enrichment_status' => 'enriched',
            'scam_type_code' => 'ROMANCE',
            'scam_type_attck' => null,
            'persona_code' => 'lonely_person',
            'extraction_method' => 'llm',
            'revelation_turn' => 3,
            'revelation_turn_ratio' => 0.5,
            'total_turns' => 6,
            'engagement_hours' => 12.5,
            'co_revealed_types' => '{}',
            'semantic_role' => 'PAYMENT_DESTINATION',
            'stimulus_type' => 'TRUST_BUILDING',
            'urgency_score' => 0.87,
            'context_excerpt' => 'Scammer requested payment after trust phase',
            'enrichment_confidence' => 0.91,
            'enrichment_model' => 'gpt-4o-mini',
            'hesitation_detected' => true,
            'language_switch' => false,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        self::assertSame('enriched', $result['enrichment_status']);
        self::assertSame('PAYMENT_DESTINATION', $result['semantic_role']);
        self::assertSame('TRUST_BUILDING', $result['stimulus_type']);
        self::assertSame(0.87, $result['urgency_score']);
        self::assertSame('Scammer requested payment after trust phase', $result['context_excerpt']);
        self::assertSame(0.91, $result['enrichment_confidence']);
        self::assertSame([], $result['co_revealed_ioc_types']);

        // LLM-only fields surfaced for enriched rows
        self::assertSame('gpt-4o-mini', $result['enrichment_model']);
        self::assertTrue($result['hesitation_detected']);
        self::assertFalse($result['language_switch']);
    }

    public function testEnrichedContextWithNullLlmFieldsDropsThem(): void
    {
        $row = [
            'enrichment_status' => 'enriched',
            'persona_code' => 'lonely_person',
            'enrichment_model' => null,
            'hesitation_detected' => null,
            'language_switch' => null,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        self::assertArrayNotHasKey('enrichment_model', $result);
        self::assertArrayNotHasKey('hesitation_detected', $result);
        self::assertArrayNotHasKey('language_switch', $result);
    }

    public function testLanguageSwitchFalseIsStillEmitted(): void
    {
        $row = [
            'enrichment_status' => 'enriched',
            'persona_code' => 'lonely_person',
            'language_switch' => false,
            'hesitation_detected' => false,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        // array_filter() drops null but keeps false — provenance for "we
        // looked, the answer was no" must reach the consumer.
        self::assertArrayHasKey('language_switch', $result);
        self::assertFalse($result['language_switch']);
        self::assertArrayHasKey('hesitation_detected', $result);
        self::assertFalse($result['hesitation_detected']);
    }

    public function testNullValuesAreStrippedFromOutput(): void
    {
        $row = [
            'enrichment_status' => 'structural',
            'scam_type_code' => null,
            'scam_type_attck' => null,
            'persona_code' => 'generic_user',
            'extraction_method' => null,
            'revelation_turn' => null,
            'revelation_turn_ratio' => null,
            'total_turns' => null,
            'engagement_hours' => null,
            'co_revealed_types' => null,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        self::assertArrayNotHasKey('scam_type', $result);
        self::assertArrayNotHasKey('attck_technique', $result);
        self::assertArrayNotHasKey('extraction_method', $result);
        self::assertArrayHasKey('persona_code', $result);
    }
}
