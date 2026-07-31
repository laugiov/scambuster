<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Validation;

use App\Domain\Validation\StructuredCorrection;
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

        // avg = (2+3+3)/3 = 2.67 >= 2.5, naturalness >= 2, ti_value >= 3 → approved
        $this->assertTrue($result->approved);
    }

    /**
     * passive replies (ti_value=2) are now REJECTED at the
     * validator gate, even when naturalness/persona_fit compensate in the average.
     * ti_value=2 means "passive / does not advance threat intelligence collection".
     */
    public function testFromLLMResponseRejectedWhenTiValueIsTwo(): void
    {
        $data = [
            'naturalness' => 4,
            'persona_fit' => 4,
            'ti_value' => 2, // passive — must now reject
            'security_pass' => true,
            'feedback' => 'Reads well but passive',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // avg = (4+4+2)/3 = 3.33 — used to be approved, now rejected by ti_value < 3
        $this->assertFalse($result->approved, 'Reply with ti_value=2 must be rejected even when avg >= 2.5');
        $this->assertSame(3.33, $result->averageQualityScore(), 'Average score unchanged — only the gate tightened');
    }

    /**
     * dead-end replies (ti_value=1) are REJECTED regardless of
     * how good naturalness/persona_fit are. ti_value=1 means "shuts down the conversation".
     */
    public function testFromLLMResponseRejectedWhenTiValueIsOne(): void
    {
        $data = [
            'naturalness' => 5,
            'persona_fit' => 5,
            'ti_value' => 1, // dead end — must reject
            'security_pass' => true,
            'feedback' => 'Beautifully written but kills the conversation',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // avg = 3.67 — used to be approved, now rejected by ti_value < 3
        $this->assertFalse($result->approved, 'Reply with ti_value=1 must be rejected regardless of other scores');
    }

    /**
     * boundary case: ti_value=3 ("maintains engagement") must
     * still be APPROVED. The gate is `ti_value >= 3`, not `> 3`.
     */
    public function testFromLLMResponseApprovedAtTiValueBoundary(): void
    {
        $data = [
            'naturalness' => 3,
            'persona_fit' => 3,
            'ti_value' => 3, // exactly at the boundary — must approve
            'security_pass' => true,
            'feedback' => 'Acceptable — maintains engagement',
        ];

        $result = ValidationResult::fromLLMResponse($data);

        // avg = 3.0 >= 2.5, naturalness >= 2, ti_value >= 3 → approved
        $this->assertTrue($result->approved, 'Reply with ti_value=3 (boundary) must be approved');
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

    /**
     * toLegacyArray() now includes the 4 score fields so that
     * RetryCoordinator and downstream consumers (ReplyHandler audit_log) can
     * persist them. Backward-compatible extension — existing callers ignore
     * unknown keys.
     */
    public function testToLegacyArrayIncludesScores(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 4,
            personaFit: 3,
            tiValue: 5,
            securityPass: true,
            feedback: 'Good',
        );

        $legacy = $result->toLegacyArray();

        $this->assertSame(4, $legacy['naturalness'], 'naturalness must be exposed via toLegacyArray');
        $this->assertSame(3, $legacy['persona_fit'], 'persona_fit must be exposed via toLegacyArray');
        $this->assertSame(5, $legacy['ti_value'], 'ti_value must be exposed via toLegacyArray');
        $this->assertTrue($legacy['security_pass'], 'security_pass must be exposed via toLegacyArray');
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

    // ─── structured correction parsing ───────────────────

    public function test_fromLLMResponse_parses_correction_when_present_and_valid(): void
    {
        $generatedText = "Hello.\n\nBest regards,\n[Your Name]";
        $data = [
            'naturalness' => 2,
            'persona_fit' => 3,
            'ti_value' => 3,
            'security_pass' => false,
            'feedback' => 'Placeholder name leak.',
            'correction' => [
                'problem_span' => "Best regards,\n[Your Name]",
                'replacement' => '',
                'rationale' => 'Sentinel must not sign with a placeholder.',
            ],
        ];

        $result = ValidationResult::fromLLMResponse($data, $generatedText);

        $this->assertNotNull($result->correction);
        $this->assertSame("Best regards,\n[Your Name]", $result->correction->problemSpan);
        $this->assertSame('', $result->correction->replacement);
        $this->assertSame('Sentinel must not sign with a placeholder.', $result->correction->rationale);
    }

    public function test_fromLLMResponse_sets_correction_null_when_field_absent(): void
    {
        $data = [
            'naturalness' => 4,
            'persona_fit' => 4,
            'ti_value' => 4,
            'security_pass' => true,
            'feedback' => 'OK',
        ];

        $result = ValidationResult::fromLLMResponse($data, 'some text');

        $this->assertNull($result->correction);
    }

    public function test_fromLLMResponse_sets_correction_null_when_generatedText_not_provided(): void
    {
        // Legacy 1-arg callers: even if correction is in data, we can't
        // validate its problem_span without the original text, so we return
        // null — fail closed.
        $data = [
            'naturalness' => 2,
            'persona_fit' => 3,
            'ti_value' => 3,
            'security_pass' => false,
            'feedback' => 'foo',
            'correction' => [
                'problem_span' => 'irrelevant',
                'replacement' => '',
                'rationale' => 'r',
            ],
        ];

        $result = ValidationResult::fromLLMResponse($data);

        $this->assertNull($result->correction);
    }

    public function test_fromLLMResponse_sets_correction_null_when_problem_span_not_in_text(): void
    {
        // The validator hallucinated a problem_span that isn't a substring
        // of the generated text — discard, fail closed.
        $data = [
            'naturalness' => 2,
            'persona_fit' => 3,
            'ti_value' => 3,
            'security_pass' => false,
            'feedback' => 'bar',
            'correction' => [
                'problem_span' => 'This phrase does not exist anywhere in the input',
                'replacement' => '',
                'rationale' => 'r',
            ],
        ];

        $result = ValidationResult::fromLLMResponse(
            $data,
            'Some completely different generated text.',
        );

        $this->assertNull($result->correction);
    }

    public function test_toLegacyArray_exposes_correction_when_set(): void
    {
        $correction = new StructuredCorrection('a', 'b', 'c');
        $result = new ValidationResult(
            approved: false,
            naturalness: 2,
            personaFit: 2,
            tiValue: 3,
            securityPass: false,
            feedback: 'fail',
            correction: $correction,
        );

        $legacy = $result->toLegacyArray();

        $this->assertSame([
            'problem_span' => 'a',
            'replacement' => 'b',
            'rationale' => 'c',
        ], $legacy['correction']);
    }

    public function test_toLegacyArray_exposes_null_correction(): void
    {
        $result = new ValidationResult(
            approved: true,
            naturalness: 4,
            personaFit: 4,
            tiValue: 4,
            securityPass: true,
            feedback: 'OK',
        );

        $legacy = $result->toLegacyArray();

        $this->assertArrayHasKey('correction', $legacy);
        $this->assertNull($legacy['correction']);
    }
}
