<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Original intent: urgency few-shot calibration coverage.
 * Current (Phase E-S5): the 10-example "Calibration examples"
 * format was replaced by a tighter 6-anchor scale (0.05 / 0.20 / 0.40 /
 * 0.60 / 0.80 / 0.95) because the dense-example format contributed to
 * clumping in the production output (urgency p50 stuck at 0.50).
 *
 * This file preserves the test INTENT (verify urgency calibration is
 * present and anti-anchored) against the new mechanism.
 */
final class UrgencyFewShotTest extends TestCase
{
    private string $promptText;

    protected function setUp(): void
    {
        $llmClient = $this->createMock(LLMClientInterface::class);
        $anonymizer = new MessageAnonymizer();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $enricher = new ContextualEnricher($llmClient, $anonymizer, $dispatcher, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()));

        $ref = new \ReflectionMethod($enricher, 'fallbackPromptTemplate');
        $this->promptText = $ref->invoke($enricher);
    }

    /**
     * @dataProvider anchorScoresProvider
     */
    public function test_prompt_contains_anchor_score(string $score): void
    {
        $this->assertStringContainsString(
            $score,
            $this->promptText,
            "Prompt must contain urgency anchor score {$score}",
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function anchorScoresProvider(): iterable
    {
        // v2 anchors — sparser than the 10-example format,
        // chosen to discourage clustering on mid-range defaults.
        yield 'anchor 0.05 (marketing nudge)' => ['0.05'];
        yield 'anchor 0.20 (polite follow-up)' => ['0.20'];
        yield 'anchor 0.40 (clear request)' => ['0.40'];
        yield 'anchor 0.60 (firm deadline)' => ['0.60'];
        yield 'anchor 0.80 (explicit threat)' => ['0.80'];
        yield 'anchor 0.95 (ultimatum)' => ['0.95'];
    }

    public function test_prompt_anti_anchoring_instruction(): void
    {
        $this->assertStringContainsString(
            'Do NOT default to 0.5 or 0.75',
            $this->promptText,
            'Anti-anchoring instruction must be preserved (covers both common LLM defaults)',
        );
    }

    public function test_prompt_anchors_are_illustrative_not_buckets(): void
    {
        // v2 phrasing — explicit that anchors are reference points,
        // not categorical buckets the LLM should snap to.
        $this->assertStringContainsString(
            'anchors are illustrative, not buckets',
            $this->promptText,
            'Prompt must clarify anchors are not bucketed values',
        );
    }
}
