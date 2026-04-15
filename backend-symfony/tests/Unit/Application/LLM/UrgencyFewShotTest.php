<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Spec 075a: urgency few-shot calibration examples.
 *
 * Verifies the fallback prompt template contains concrete examples
 * spanning the full 0.00-1.00 range to combat LLM anchoring on 0.75.
 */
final class UrgencyFewShotTest extends TestCase
{
    private string $promptText;

    protected function setUp(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $anonymizer = new MessageAnonymizer();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $enricher = new ContextualEnricher($llmClient, $anonymizer, $dispatcher, new NullLogger());

        $ref = new \ReflectionMethod($enricher, 'fallbackPromptTemplate');
        $this->promptText = $ref->invoke($enricher);
    }

    public function test_prompt_contains_calibration_examples_header(): void
    {
        $this->assertStringContainsString(
            'Calibration examples',
            $this->promptText,
            'Prompt must contain "Calibration examples" section header',
        );
    }

    /**
     * @dataProvider fewShotScoresProvider
     */
    public function test_prompt_contains_example_score(string $score): void
    {
        $this->assertStringContainsString(
            "(score: $score)",
            $this->promptText,
            "Prompt must contain few-shot example with score $score",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fewShotScoresProvider(): iterable
    {
        yield 'score 0.05' => ['0.05'];
        yield 'score 0.15' => ['0.15'];
        yield 'score 0.30' => ['0.30'];
        yield 'score 0.45' => ['0.45'];
        yield 'score 0.55' => ['0.55'];
        yield 'score 0.65' => ['0.65'];
        yield 'score 0.75' => ['0.75'];
        yield 'score 0.85' => ['0.85'];
        yield 'score 0.92' => ['0.92'];
        yield 'score 0.98' => ['0.98'];
    }

    public function test_prompt_still_contains_do_not_default_to_075(): void
    {
        $this->assertStringContainsString(
            'Do NOT default to 0.75',
            $this->promptText,
            'Anti-anchoring instruction must be preserved',
        );
    }

    public function test_prompt_contains_full_range_instruction(): void
    {
        $this->assertStringContainsString(
            'FULL range',
            $this->promptText,
            'Prompt must reference the FULL range for scoring',
        );
    }
}
