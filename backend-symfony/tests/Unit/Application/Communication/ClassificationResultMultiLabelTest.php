<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ClassificationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ClassificationResult multi-label (secondary types) support.
 */
final class ClassificationResultMultiLabelTest extends TestCase
{
    public function testSecondaryTypesPopulated(): void
    {
        $secondaryTypes = [
            ['code' => 'ROMANCE', 'confidence' => 0.6],
            ['code' => 'INVOICE_FRAUD', 'confidence' => 0.4],
        ];

        $result = new ClassificationResult(
            scamTypeCode: 'ADVANCE_FEE_419',
            confidence: 0.92,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Hybrid scam with romance and invoice elements',
            secondaryTypes: $secondaryTypes,
        );

        $this->assertSame($secondaryTypes, $result->secondaryTypes);
        $this->assertCount(2, $result->secondaryTypes);
        $this->assertSame('ROMANCE', $result->secondaryTypes[0]['code']);
        $this->assertSame(0.6, $result->secondaryTypes[0]['confidence']);
        $this->assertSame('INVOICE_FRAUD', $result->secondaryTypes[1]['code']);
        $this->assertSame(0.4, $result->secondaryTypes[1]['confidence']);
    }

    public function testSecondaryTypesNullByDefault(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Standard phishing',
        );

        $this->assertNull($result->secondaryTypes);
    }

    public function testShouldApplyStillWorksWithSecondaryTypes(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'ROMANCE',
            confidence: 0.85,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Romance scam with secondary types',
            secondaryTypes: [
                ['code' => 'INVOICE_FRAUD', 'confidence' => 0.5],
            ],
        );

        $this->assertTrue($result->shouldApply(0.75));
        $this->assertFalse($result->shouldApply(0.90));
    }

    public function testBackwardCompatWithAllExistingParams(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'NEW_TYPE',
            confidence: 0.88,
            isNewType: true,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'New type',
            personaData: ['label_en' => 'New', 'label_fr' => 'Nouveau'],
            suggestedPersonaCodes: ['generic_user'],
            detectedLanguage: 'fr',
        );

        // secondaryTypes should be null when not passed
        $this->assertNull($result->secondaryTypes);
        // All other properties still work
        $this->assertSame('NEW_TYPE', $result->scamTypeCode);
        $this->assertSame('fr', $result->detectedLanguage);
        $this->assertNotNull($result->getSuggestedPersonaCodes());
    }

    public function testSecondaryTypesWithAllExistingParams(): void
    {
        $secondaryTypes = [
            ['code' => 'CHARITY', 'confidence' => 0.55],
        ];

        $result = new ClassificationResult(
            scamTypeCode: 'ROMANCE',
            confidence: 0.91,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Romance with charity angle',
            personaData: null,
            suggestedPersonaCodes: null,
            detectedLanguage: 'en',
            secondaryTypes: $secondaryTypes,
        );

        $this->assertSame($secondaryTypes, $result->secondaryTypes);
        $this->assertSame('en', $result->detectedLanguage);
    }
}
