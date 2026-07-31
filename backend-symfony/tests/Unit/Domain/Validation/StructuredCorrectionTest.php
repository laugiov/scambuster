<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Validation;

use App\Domain\Validation\StructuredCorrection;
use PHPUnit\Framework\TestCase;

/**
 * StructuredCorrection parsing contract.
 */
final class StructuredCorrectionTest extends TestCase
{
    private const SAMPLE_GENERATED_TEXT = "Hello,\n\nPlease send your IBAN.\n\nBest regards,\n[Your Name]";

    public function test_fromLLMResponse_returns_instance_on_valid_input(): void
    {
        $data = [
            'problem_span' => "Best regards,\n[Your Name]",
            'replacement' => '',
            'rationale' => 'Sentinel must not sign with a placeholder name.',
        ];

        $result = StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT);

        self::assertInstanceOf(StructuredCorrection::class, $result);
        self::assertSame("Best regards,\n[Your Name]", $result->problemSpan);
        self::assertSame('', $result->replacement);
        self::assertSame('Sentinel must not sign with a placeholder name.', $result->rationale);
    }

    public function test_fromLLMResponse_returns_null_when_data_is_null(): void
    {
        self::assertNull(StructuredCorrection::fromLLMResponse(null, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_when_generated_text_is_null(): void
    {
        $data = [
            'problem_span' => 'irrelevant',
            'replacement' => '',
            'rationale' => 'r',
        ];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, null));
    }

    public function test_fromLLMResponse_returns_null_when_problem_span_missing(): void
    {
        $data = ['replacement' => '', 'rationale' => 'r'];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_when_replacement_missing(): void
    {
        $data = ['problem_span' => 'Hello', 'rationale' => 'r'];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_when_rationale_missing(): void
    {
        $data = ['problem_span' => 'Hello', 'replacement' => ''];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_when_problem_span_not_a_string(): void
    {
        $data = ['problem_span' => 123, 'replacement' => '', 'rationale' => 'r'];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_when_problem_span_not_in_text(): void
    {
        $data = [
            'problem_span' => 'This phrase does not exist in the generated text',
            'replacement' => '',
            'rationale' => 'r',
        ];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_returns_null_on_empty_problem_span(): void
    {
        $data = ['problem_span' => '', 'replacement' => 'x', 'rationale' => 'r'];
        self::assertNull(StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT));
    }

    public function test_fromLLMResponse_allows_empty_replacement_as_deletion(): void
    {
        $data = [
            'problem_span' => 'Please send your IBAN.',
            'replacement' => '',
            'rationale' => 'Removed sensitive ask.',
        ];

        $result = StructuredCorrection::fromLLMResponse($data, self::SAMPLE_GENERATED_TEXT);

        self::assertInstanceOf(StructuredCorrection::class, $result);
        self::assertSame('', $result->replacement);
    }

    public function test_toArray_round_trips(): void
    {
        $c = new StructuredCorrection('a', 'b', 'c');

        self::assertSame([
            'problem_span' => 'a',
            'replacement' => 'b',
            'rationale' => 'c',
        ], $c->toArray());
    }
}
