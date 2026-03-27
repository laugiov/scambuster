<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Validation;

use App\Domain\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

class ValidationResultTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 4,
            personaFit: 3,
            tiValue: 5,
            securityPass: true,
            feedback: 'Good reply',
            reasons: ['Natural tone', 'Good elicitation'],
            fixSuggestion: null,
        );

        $this->assertTrue($result->approved);
        $this->assertSame(4, $result->naturalness);
        $this->assertSame(3, $result->personaFit);
        $this->assertSame(5, $result->tiValue);
        $this->assertTrue($result->securityPass);
        $this->assertSame('Good reply', $result->feedback);
        $this->assertCount(2, $result->reasons);
        $this->assertNull($result->fixSuggestion);
    }

    public function testAverageQualityScore(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 4,
            personaFit: 3,
            tiValue: 5,
            securityPass: true,
            feedback: '',
        );

        // (4 + 3 + 5) / 3 = 4.0
        $this->assertSame(4.0, $result->averageQualityScore());
    }

    public function testAverageQualityScoreRounding(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 3,
            personaFit: 4,
            tiValue: 4,
            securityPass: true,
            feedback: '',
        );

        // (3 + 4 + 4) / 3 = 3.67
        $this->assertSame(3.67, $result->averageQualityScore());
    }

    public function testFromLLMResponseApproved(): void
    {
        $data = [
            'naturalness' => 4,
            'naturalness_reasoning' => 'Reads naturally',
            'persona_fit' => 3,
            'persona_fit_reasoning' => 'Matches persona voice',
            'ti_value' => 4,
            'ti_value_reasoning' => 'Good elicitation attempt',
            'security_pass' => true,
            'security_reasoning' => 'No forbidden words',
            'feedback' => 'Solid reply overall',
            'fix_suggestion' => null,
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertTrue($result->approved);
        $this->assertSame(4, $result->naturalness);
        $this->assertSame(3, $result->personaFit);
        $this->assertSame(4, $result->tiValue);
        $this->assertTrue($result->securityPass);
        $this->assertSame('Solid reply overall', $result->feedback);
        $this->assertCount(4, $result->reasons);
        $this->assertNull($result->fixSuggestion);
    }

    public function testFromLLMResponseRejectedSecurityFail(): void
    {
        $data = [
            'naturalness' => 5,
            'persona_fit' => 5,
            'ti_value' => 5,
            'security_pass' => false,
            'security_reasoning' => 'Contains "honeypot" word',
            'feedback' => 'Security violation',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertFalse($result->approved);
        $this->assertFalse($result->securityPass);
        // Even with perfect scores, security fail rejects
        $this->assertSame(5.0, $result->averageQualityScore());
    }

    public function testFromLLMResponseRejectedLowNaturalness(): void
    {
        $data = [
            'naturalness' => 1,
            'persona_fit' => 4,
            'ti_value' => 4,
            'security_pass' => true,
            'feedback' => 'Reads like a bot',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertFalse($result->approved);
        $this->assertSame(1, $result->naturalness);
    }

    public function testFromLLMResponseRejectedLowAverage(): void
    {
        $data = [
            'naturalness' => 2,
            'persona_fit' => 1,
            'ti_value' => 1,
            'security_pass' => true,
            'feedback' => 'Poor quality',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // avg = (2+1+1)/3 = 1.33 < 2.5 → rejected
        $this->assertFalse($result->approved);
        $this->assertSame(2, $result->naturalness);
    }

    public function testFromLLMResponseBorderlineApproved(): void
    {
        $data = [
            'naturalness' => 2,
            'persona_fit' => 3,
            'ti_value' => 3,
            'security_pass' => true,
            'feedback' => 'Borderline acceptable',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // avg = (2+3+3)/3 = 2.67 >= 2.5 and naturalness >= 2 → approved
        $this->assertTrue($result->approved);
    }

    public function testFromLLMResponseClampsScores(): void
    {
        $data = [
            'naturalness' => 10,
            'persona_fit' => -3,
            'ti_value' => 0,
            'security_pass' => true,
            'feedback' => '',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertSame(5, $result->naturalness);
        $this->assertSame(1, $result->personaFit);
        $this->assertSame(1, $result->tiValue);
    }

    public function testFromLLMResponseMissingFieldsDefaultSafely(): void
    {
        $data = [
            'security_pass' => true,
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // Missing scores default to 1
        $this->assertSame(1, $result->naturalness);
        $this->assertSame(1, $result->personaFit);
        $this->assertSame(1, $result->tiValue);
        $this->assertSame('', $result->feedback);
        // avg = 1.0 < 2.5 → rejected despite security pass
        $this->assertFalse($result->approved);
    }

    public function testToLegacyArray(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 4,
            personaFit: 3,
            tiValue: 5,
            securityPass: true,
            feedback: 'Good',
            reasons: ['reason 1', 'reason 2'],
            fixSuggestion: 'Improve tone',
        );

        $legacy = $result->toLegacyArray();

        $this->assertTrue($legacy['approved']);
        $this->assertCount(2, $legacy['reasons']);
        $this->assertSame('Improve tone', $legacy['fix_suggestion']);
    }

    public function testFromLLMResponseWithFixSuggestion(): void
    {
        $data = [
            'naturalness' => 2,
            'persona_fit' => 1,
            'ti_value' => 2,
            'security_pass' => true,
            'feedback' => 'Needs work',
            'fix_suggestion' => 'Use more casual language',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertSame('Use more casual language', $result->fixSuggestion);
    }
}
