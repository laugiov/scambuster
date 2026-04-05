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
            'persona_code' => 'elderly_person',
            'extraction_method' => 'regex',
            'revelation_turn' => 7,
            'revelation_turn_ratio' => 0.35,
            'total_turns' => 20,
            'engagement_hours' => 48.7,
            'co_revealed_types' => '{iban,phone}',
            'semantic_role' => null,
            'stimulus_type' => null,
            'urgency_score' => null,
            'context_excerpt' => null,
            'enrichment_confidence' => null,
        ];

        $result = IocContextStixExtensionBuilder::build($row);

        self::assertNotNull($result);
        self::assertSame('1.0', $result['schema_version']);
        self::assertSame('structural', $result['enrichment_status']);
        self::assertSame('ADVANCE_FEE_419', $result['scam_type']);
        self::assertSame('T1566.001', $result['attck_technique']);
        self::assertSame('elderly_person', $result['persona_code']);
        self::assertSame(7, $result['revelation_turn']);
        self::assertSame(0.35, $result['revelation_turn_ratio']);
        self::assertSame(20, $result['total_turns']);
        self::assertSame(48.7, $result['engagement_hours']);
        self::assertSame(['iban', 'phone'], $result['co_revealed_ioc_types']);

        // No semantic fields
        self::assertArrayNotHasKey('semantic_role', $result);
        self::assertArrayNotHasKey('stimulus_type', $result);
        self::assertArrayNotHasKey('urgency_score', $result);
        self::assertArrayNotHasKey('context_excerpt', $result);
        self::assertArrayNotHasKey('enrichment_confidence', $result);
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
