<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\ClassificationResult;
use App\Application\LLM\ContextualEnrichmentResult;
use PHPUnit\Framework\TestCase;

/**
 * Epistemic validation: LLM output integrity tests.
 *
 * Verifies that factory methods and constructors enforce documented
 * constraints on LLM-derived values. These tests serve as regression
 * guards ensuring that raw LLM output is always sanitized before use.
 *
 * @covers \App\Application\LLM\ContextualEnrichmentResult
 * @covers \App\Application\Communication\ClassificationResult
 */
final class LlmOutputIntegrityTest extends TestCase
{
    // ================================================================== //
    //  ContextualEnrichmentResult: urgency_score clamping
    // ================================================================== //

    public function testUrgencyScoreClampedFromNegative(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => -0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(0.0, $result->urgencyScore, 'Negative urgency should be clamped to 0.0');
    }

    public function testUrgencyScoreClampedFromAboveOne(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 1.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(1.0, $result->urgencyScore, 'Urgency > 1.0 should be clamped to 1.0');
    }

    public function testUrgencyScoreClampedFromLargeNegative(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => -100.0,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(0.0, $result->urgencyScore);
    }

    public function testUrgencyScoreValidValuePassesThrough(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.73,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertEqualsWithDelta(0.73, $result->urgencyScore, 0.001);
    }

    public function testUrgencyScoreMissingDefaultsToZero(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(0.0, $result->urgencyScore, 'Missing urgency should default to 0.0');
    }

    // ================================================================== //
    //  ContextualEnrichmentResult: stimulus_type validation
    // ================================================================== //

    public function testInvalidStimulusTypeDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => 'TOTALLY_MADE_UP',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame('UNKNOWN', $result->stimulusType, 'Invalid stimulus type should fall back to UNKNOWN');
    }

    public function testEmptyStimulusTypeDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => '',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame('UNKNOWN', $result->stimulusType);
    }

    public function testNullStimulusTypeDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => null,
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame('UNKNOWN', $result->stimulusType);
    }

    public function testAllValidStimulusTypesAccepted(): void
    {
        $validTypes = [
            'URGENCY_PRESSURE',
            'TRUST_BUILDING',
            'DIRECT_REQUEST',
            'DOCUMENT_REQUEST',
            'PAYMENT_INITIATION',
            'PASSIVE',
            'UNKNOWN',
        ];

        foreach ($validTypes as $type) {
            $data = [
                'stimulus_type' => $type,
                'scammer_urgency_score' => 0.5,
                'ioc_roles' => [],
            ];

            $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
            $this->assertSame($type, $result->stimulusType, "Valid stimulus type '{$type}' should be accepted");
        }
    }

    // ================================================================== //
    //  ContextualEnrichmentResult: ioc_roles validation
    // ================================================================== //

    public function testInvalidIocRoleFallsBackToUnknown(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'HALLUCINATED_ROLE'],
            ],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['url'], 'Invalid IOC role should fall back to UNKNOWN');
    }

    public function testMissingIocTypeFilledWithUnknown(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
            ],
        ];

        // Request roles for url AND iban, but LLM only provided url
        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url', 'iban']);
        $this->assertSame('PAYMENT_REDIRECT_URL', $result->iocRoles['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['iban'], 'Missing IOC type should be filled with UNKNOWN');
    }

    public function testAllValidIocRolesAccepted(): void
    {
        $validRoles = [
            'PAYMENT_DESTINATION',
            'PAYMENT_REDIRECT_URL',
            'PHISHING_CREDENTIAL_URL',
            'MALWARE_DOWNLOAD_URL',
            'CONTACT_CHANNEL',
            'IDENTITY_DOCUMENT',
            'VERIFICATION_CODE_URL',
            'INFRASTRUCTURE_DOMAIN',
            'MONEY_MULE_ACCOUNT',
            'UNKNOWN',
        ];

        foreach ($validRoles as $role) {
            $data = [
                'stimulus_type' => 'PASSIVE',
                'scammer_urgency_score' => 0.5,
                'ioc_roles' => [
                    ['type' => 'test_type', 'role' => $role],
                ],
            ];

            $result = ContextualEnrichmentResult::fromLlmResponse($data, ['test_type']);
            $this->assertSame($role, $result->iocRoles['test_type'], "Valid IOC role '{$role}' should be accepted");
        }
    }

    public function testMalformedIocRolesArrayHandledGracefully(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => 'not_an_array',
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['url'], 'Non-array ioc_roles should result in UNKNOWN');
    }

    // ================================================================== //
    //  ContextualEnrichmentResult: confidence capping by message count
    // ================================================================== //

    public function testConfidenceCappedAt060With1Message(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.60,
            $result->enrichmentConfidence,
            0.001,
            '1 message window: confidence should be capped at 0.60',
        );
    }

    public function testConfidenceCappedAt080With2Messages(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 2);
        $this->assertEqualsWithDelta(
            0.80,
            $result->enrichmentConfidence,
            0.001,
            '2 message window: confidence should be capped at 0.80',
        );
    }

    public function testConfidenceNotCappedWith3Messages(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 3);
        $this->assertEqualsWithDelta(
            0.95,
            $result->enrichmentConfidence,
            0.001,
            '3 message window: confidence should NOT be capped',
        );
    }

    public function testConfidenceBelowCapUnchanged(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.40,
            'ioc_roles' => [],
        ];

        // 1 message cap is 0.60, but confidence=0.40 < 0.60 → no capping
        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.40,
            $result->enrichmentConfidence,
            0.001,
            'Confidence below cap should pass through unchanged',
        );
    }

    public function testConfidenceClampedToOneFromAbove(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 2.0,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 3);
        $this->assertSame(1.0, $result->enrichmentConfidence, 'Confidence > 1.0 should be clamped to 1.0');
    }

    public function testConfidenceClampedToZeroFromNegative(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => -0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 3);
        $this->assertSame(0.0, $result->enrichmentConfidence, 'Negative confidence should be clamped to 0.0');
    }

    // ================================================================== //
    //  ContextualEnrichmentResult: context excerpt truncation
    // ================================================================== //

    public function testContextExcerptTruncatedAt295(): void
    {
        $longExcerpt = str_repeat('A', 500);
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'context_excerpt' => $longExcerpt,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(295, mb_strlen($result->contextExcerpt), 'Context excerpt should be truncated to 295 chars');
    }

    // ================================================================== //
    //  ContextualEnrichmentResult: complete empty input
    // ================================================================== //

    public function testCompletelyEmptyLlmResponseHandled(): void
    {
        $result = ContextualEnrichmentResult::fromLlmResponse([], ['url', 'iban']);

        $this->assertSame('UNKNOWN', $result->stimulusType);
        $this->assertSame(0.0, $result->urgencyScore);
        $this->assertFalse($result->languageSwitch);
        $this->assertFalse($result->hesitationDetected);
        $this->assertSame('', $result->contextExcerpt);
        $this->assertSame(0.0, $result->enrichmentConfidence);
        $this->assertSame('UNKNOWN', $result->iocRoles['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['iban']);
    }

    // ================================================================== //
    //  ClassificationResult: constructor constraints
    // ================================================================== //

    public function testClassificationResultScamTypeCodeIsString(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.85,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'Classic phishing email',
        );

        $this->assertSame('PHISHING', $result->scamTypeCode);
        $this->assertIsString($result->scamTypeCode);
    }

    public function testClassificationResultConfidenceIsFloat(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'INVOICE_FRAUD',
            confidence: 0.92,
            isNewType: false,
            isNewPersona: false,
            personaCode: 'generic_user',
            reasoning: 'Invoice fraud pattern detected',
        );

        // Verify the confidence property holds the expected value
        $this->assertSame(0.92, $result->confidence);
    }

    public function testClassificationResultUpperSnakeCaseConvention(): void
    {
        // Verify known scam types follow UPPER_SNAKE_CASE
        $knownTypes = [
            'PHISHING',
            'INVOICE_FRAUD',
            'CEO_FRAUD',
            'ROMANCE',
            'LOTTERY',
            'ADVANCE_FEE_419',
            'INVESTMENT',
            'TECH_SUPPORT',
        ];

        foreach ($knownTypes as $type) {
            $this->assertMatchesRegularExpression(
                '/^[A-Z][A-Z0-9_]*$/',
                $type,
                "Scam type '{$type}' should match UPPER_SNAKE_CASE pattern",
            );
        }
    }

    public function testClassificationResultDefaultValues(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.90,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertNull($result->personaData, 'personaData should default to null');
        $this->assertNull($result->suggestedPersonaCodes, 'suggestedPersonaCodes should default to null');
        $this->assertSame('en', $result->detectedLanguage, 'detectedLanguage should default to en');
    }

    public function testClassificationResultShouldApplyThreshold(): void
    {
        $high = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.85,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $low = new ClassificationResult(
            scamTypeCode: 'PHISHING',
            confidence: 0.60,
            isNewType: false,
            isNewPersona: false,
            personaCode: null,
            reasoning: 'test',
        );

        $this->assertTrue($high->shouldApply(0.75), 'Confidence 0.85 should pass 0.75 threshold');
        $this->assertFalse($low->shouldApply(0.75), 'Confidence 0.60 should fail 0.75 threshold');
    }

    public function testClassificationResultWithAllOptionalFields(): void
    {
        $result = new ClassificationResult(
            scamTypeCode: 'NEW_SCAM_TYPE',
            confidence: 0.88,
            isNewType: true,
            isNewPersona: true,
            personaCode: 'custom_persona',
            reasoning: 'Newly identified scam pattern',
            personaData: ['persona_code' => 'custom_persona', 'persona_label' => 'Custom'],
            suggestedPersonaCodes: ['generic_user', 'elderly_person'],
            detectedLanguage: 'fr',
        );

        $this->assertTrue($result->isNewType);
        $this->assertTrue($result->isNewPersona);
        $this->assertSame('custom_persona', $result->personaCode);
        $personaData = $result->getPersonaData();
        $this->assertIsArray($personaData);
        $this->assertSame('Custom', $personaData['persona_label'] ?? null);
        $this->assertSame(['generic_user', 'elderly_person'], $result->getSuggestedPersonaCodes());
        $this->assertSame('fr', $result->detectedLanguage);
    }
}
