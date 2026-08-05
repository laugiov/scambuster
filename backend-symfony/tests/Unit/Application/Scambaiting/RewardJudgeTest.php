<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Scambaiting;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\Scambaiting\RewardJudge;
use App\Domain\Scambaiting\ConversationMetrics;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RewardJudgeTest extends TestCase
{
    /** @var list<array{direction: string, body_text: string}> */
    private array $messages;
    private ConversationMetrics $metrics;

    protected function setUp(): void
    {
        $this->messages = [
            ['direction' => 'in', 'body_text' => 'Send payment to finalize.'],
            ['direction' => 'out', 'body_text' => 'Sure, where do I send it?'],
        ];
        $this->metrics = new ConversationMetrics(600, 5, 2, true);
    }

    private function judgeReturning(string $response, float $weight = 0.7): RewardJudge
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn($response);

        return new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), $weight);
    }

    public function testBlendsHighOutcomeWithMechanical(): void
    {
        $judge = $this->judgeReturning('{"outcome_score": 0.9, "reason": "got the account"}');

        // 0.7*0.9 + 0.3*0.2 = 0.69
        self::assertEqualsWithDelta(0.69, $judge->hybrid(0.2, $this->messages, $this->metrics), 0.0001);
    }

    public function testLlmPullsDownAGamedMechanicalReward(): void
    {
        // Bot detected → low outcome, even though the mechanical reward was inflated.
        $judge = $this->judgeReturning('{"outcome_score": 0.1, "reason": "unmasked as a bot"}');

        // 0.7*0.1 + 0.3*0.9 = 0.34 (well below the gamed 0.9)
        $blended = $judge->hybrid(0.9, $this->messages, $this->metrics);
        self::assertEqualsWithDelta(0.34, $blended, 0.0001);
        self::assertLessThan(0.9, $blended);
    }

    public function testFallsBackToMechanicalOnLlmFailure(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willThrowException(new \RuntimeException('timeout'));
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.7);

        self::assertSame(0.55, $judge->hybrid(0.55, $this->messages, $this->metrics));
    }

    public function testFallsBackToMechanicalOnMalformedJson(): void
    {
        $judge = $this->judgeReturning('not json at all');

        self::assertSame(0.42, $judge->hybrid(0.42, $this->messages, $this->metrics));
    }

    public function testFallsBackToMechanicalOnEmptyTranscript(): void
    {
        $judge = $this->judgeReturning('{"outcome_score": 0.9}');

        self::assertSame(0.3, $judge->hybrid(0.3, [], $this->metrics));
    }

    public function testClampsOutOfRangeOutcome(): void
    {
        $judge = $this->judgeReturning('{"outcome_score": 5.0}', 1.0);

        // weight 1.0 → pure outcome, clamped to 1.0
        self::assertSame(1.0, $judge->hybrid(0.0, $this->messages, $this->metrics));
    }
}
