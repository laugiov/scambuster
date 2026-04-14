<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ClassificationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ClassificationResult value object.
 */
final class ClassificationResultTest extends TestCase
{
    public function testShouldApplyReturnsTrueAboveThreshold(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.85,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertTrue($result->shouldApply(0.75));
    }

    public function testShouldApplyReturnsFalseBelowThreshold(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.60,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertFalse($result->shouldApply(0.75));
    }

    public function testShouldApplyReturnsTrueAtExactThreshold(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.75,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertTrue($result->shouldApply(0.75));
    }

    public function testGetPersonaDataReturnsNullWhenNotSet(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertNull($result->getPersonaData());
    }

    public function testGetPersonaDataReturnsDataWhenSet(): void
    {
        $personaData = [
            'persona_code' => 'test_persona',
            'persona_label' => 'Test Persona',
            'persona_tone' => 'Friendly',
            'system_prompt' => 'You are a test persona',
        ];

        $result = new ClassificationResult(
            scamTypeCode: 'NEW_TYPE',
            confidence: 0.90,
            isNewType: true,
            isNewPersona: true,
            personaCode: 'test_persona',
            reasoning: 'test',
            personaData: $personaData,
        );

        $this->assertSame($personaData, $result->getPersonaData());
    }

    public function testGetSuggestedPersonaCodesReturnsNullWhenNotSet(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertNull($result->getSuggestedPersonaCodes());
    }

    public function testGetSuggestedPersonaCodesReturnsCodesWhenSet(): void
    {
        $codes = ['generic_user', 'elderly_person'];

        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
            suggestedPersonaCodes: $codes,
        );

        $this->assertSame($codes, $result->getSuggestedPersonaCodes());
    }

    public function testDetectedLanguageDefaultsToEn(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertSame('en', $result->detectedLanguage);
    }

    public function testDetectedLanguageCanBeOverridden(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
            detectedLanguage: 'fr',
        );

        $this->assertSame('fr', $result->detectedLanguage);
    }

    public function testPublicPropertiesAreReadonly(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.92,
            isNewType: true,
            isNewPersona: false,
            personaCode: 'generic_user',
            reasoning: 'Classic phishing',
        );

        $this->assertSame('PHISHING', $result->scamTypeCode);
        $this->assertSame(0.92, $result->confidence);
        $this->assertTrue($result->isNewType);
        $this->assertFalse($result->isNewPersona);
        $this->assertSame('generic_user', $result->personaCode);
        $this->assertSame('Classic phishing', $result->reasoning);
    }
}
