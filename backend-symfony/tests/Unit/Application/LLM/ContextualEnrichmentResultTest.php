<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ContextualEnrichmentResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Application\LLM\ContextualEnrichmentResult
 */
class ContextualEnrichmentResultTest extends TestCase
{
    public function testFromLlmResponseWithValidData(): void
    {
        $data = [
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.75,
            'language_switch_detected' => false,
            'hesitation_detected' => true,
            'context_excerpt' => 'Scammer provided payment details',
            'enrichment_confidence' => 0.85,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
                ['type' => 'iban', 'role' => 'PAYMENT_DESTINATION'],
            ],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url', 'iban']);

        $this->assertSame('DIRECT_REQUEST', $result->stimulusType);
        $this->assertSame(0.75, $result->urgencyScore);
        $this->assertFalse($result->languageSwitch);
        $this->assertTrue($result->hesitationDetected);
        $this->assertSame('Scammer provided payment details', $result->contextExcerpt);
        $this->assertSame(0.85, $result->enrichmentConfidence);
        $this->assertSame('PAYMENT_REDIRECT_URL', $result->iocRoles['url']);
        $this->assertSame('PAYMENT_DESTINATION', $result->iocRoles['iban']);
    }

    public function testInvalidStimulusTypeDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => 'INVALID_TYPE',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);

        $this->assertSame('UNKNOWN', $result->stimulusType);
    }

    public function testUrgencyScoreClampedToZeroOne(): void
    {
        $dataTooHigh = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 1.5,
            'ioc_roles' => [],
        ];

        $resultHigh = ContextualEnrichmentResult::fromLlmResponse($dataTooHigh, []);
        $this->assertSame(1.0, $resultHigh->urgencyScore);

        $dataTooLow = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => -0.3,
            'ioc_roles' => [],
        ];

        $resultLow = ContextualEnrichmentResult::fromLlmResponse($dataTooLow, []);
        $this->assertSame(0.0, $resultLow->urgencyScore);
    }

    public function testMissingIocRoleDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.5,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
            ],
        ];

        // Request includes 'iban' but LLM didn't provide a role for it
        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url', 'iban']);

        $this->assertSame('PAYMENT_REDIRECT_URL', $result->iocRoles['url']);
        $this->assertSame('UNKNOWN', $result->iocRoles['iban']);
    }

    public function testIocRolesMapCorrectly(): void
    {
        $data = [
            'stimulus_type' => 'PAYMENT_INITIATION',
            'scammer_urgency_score' => 0.9,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PHISHING_CREDENTIAL_URL'],
                ['type' => 'email', 'role' => 'CONTACT_CHANNEL'],
                ['type' => 'domain', 'role' => 'INFRASTRUCTURE_DOMAIN'],
            ],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url', 'email', 'domain']);

        $this->assertCount(3, $result->iocRoles);
        $this->assertSame('PHISHING_CREDENTIAL_URL', $result->iocRoles['url']);
        $this->assertSame('CONTACT_CHANNEL', $result->iocRoles['email']);
        $this->assertSame('INFRASTRUCTURE_DOMAIN', $result->iocRoles['domain']);
    }

    public function testInvalidIocRoleDefaultsToUnknown(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.3,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'TOTALLY_INVALID_ROLE'],
            ],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url']);

        $this->assertSame('UNKNOWN', $result->iocRoles['url']);
    }

    public function testEnrichmentConfidenceClamped(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 2.5,
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, []);
        $this->assertSame(1.0, $result->enrichmentConfidence);
    }

    public function testMissingFieldsUseDefaults(): void
    {
        $data = []; // Completely empty

        $result = ContextualEnrichmentResult::fromLlmResponse($data, ['url']);

        $this->assertSame('UNKNOWN', $result->stimulusType);
        $this->assertSame(0.0, $result->urgencyScore);
        $this->assertFalse($result->languageSwitch);
        $this->assertFalse($result->hesitationDetected);
        $this->assertSame('', $result->contextExcerpt);
        $this->assertSame(0.0, $result->enrichmentConfidence);
        $this->assertSame('UNKNOWN', $result->iocRoles['url']);
    }
}
