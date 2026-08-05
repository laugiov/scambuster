<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Scambaiting;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptProvider;
use App\Application\Scambaiting\RewardJudge;
use App\Domain\Scambaiting\ConversationMetrics;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Locks the RewardJudge rubric externalization + configurable llmWeight.
 *
 * RewardJudge feeds the epsilon-greedy bandit reward — a sensitive path — so the
 * overriding constraint is ZERO REGRESSION: with no override the rubric sent to the
 * LLM is byte-identical to before (golden sha256), the default weight is 0.7, and the
 * null-safe fallback is preserved. The added capability (rubric override, configurable
 * weight) is tested alongside.
 */
final class RewardJudgeRubricTest extends TestCase
{
    // Golden hash of the rubric (system prompt) captured pre-externalization.
    private const GOLDEN_RUBRIC = '8bc2dc333285ef20cdf24152cbea44ca5a28dfc4966b192755576640b2501e2a';

    private ConversationMetrics $metrics;

    /** @var list<array{direction: string, body_text: string}> */
    private array $messages;

    protected function setUp(): void
    {
        $this->metrics = new ConversationMetrics(600, 5, 2, true);
        $this->messages = [
            ['direction' => 'in', 'body_text' => 'Please pay the fee to release your prize.'],
            ['direction' => 'out', 'body_text' => 'How exactly should I send it?'],
        ];
    }

    /**
     * A capturing LLM stub that records the system prompt it is handed on a public
     * property (read it back after the call). No return type so the concrete type —
     * including `$captured` — is visible to the caller and to static analysis.
     */
    private function capturingLlm(): object
    {
        return new class implements LLMClientInterface {
            public string $captured = '';

            public function chat(array $messages, array $options = []): string
            {
                $this->captured = (string) ($messages[0]['content'] ?? '');

                return '{"outcome_score": 0.5, "reason": "ok"}';
            }
        };
    }

    // ─── ZERO REGRESSION: byte-identical rubric with no override ────────

    public function testRubricIsByteIdenticalToGoldenWithNoOverride(): void
    {
        $llm = $this->capturingLlm();
        $judge = new RewardJudge(
            $llm,
            new NullLogger(),
            new PromptProvider('/nonexistent-prompt-dir', new NullLogger()),
            0.7,
        );

        $judge->hybrid(0.5, $this->messages, $this->metrics);

        self::assertSame(self::GOLDEN_RUBRIC, hash('sha256', $llm->captured), 'rubric must be byte-identical to the pre-externalization default');
    }

    // ─── capability: operator override is used ─────────────────────────

    public function testOperatorOverrideRubricIsUsed(): void
    {
        $dir = sys_get_temp_dir() . '/scambuster_rjudge_' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/reward_judge.txt', 'CUSTOM RUBRIC: reply JSON {"outcome_score": <float>}');

        try {
            $llm = $this->capturingLlm();
            $judge = new RewardJudge(
                $llm,
                new NullLogger(),
                new PromptProvider($dir, new NullLogger()),
                0.7,
            );

            $judge->hybrid(0.5, $this->messages, $this->metrics);

            self::assertSame('CUSTOM RUBRIC: reply JSON {"outcome_score": <float>}', $llm->captured);
        } finally {
            @unlink($dir . '/reward_judge.txt');
            @rmdir($dir);
        }
    }

    // ─── capability: configurable llmWeight drives the blend ───────────

    public function testWeightOneUsesOutcomeOnly(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn('{"outcome_score": 0.9}');
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 1.0);

        // weight 1.0 → blend == outcome (0.9), mechanical (0.2) ignored.
        self::assertEqualsWithDelta(0.9, $judge->hybrid(0.2, $this->messages, $this->metrics), 0.0001);
    }

    public function testWeightZeroUsesMechanicalOnly(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn('{"outcome_score": 0.9}');
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.0);

        // weight 0.0 → blend == mechanical (0.2), outcome ignored.
        self::assertEqualsWithDelta(0.2, $judge->hybrid(0.2, $this->messages, $this->metrics), 0.0001);
    }

    public function testDefaultWeightBlendsSeventyThirty(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn('{"outcome_score": 0.9}');
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.7);

        // 0.7*0.9 + 0.3*0.2 = 0.69 (unchanged blend math).
        self::assertEqualsWithDelta(0.69, $judge->hybrid(0.2, $this->messages, $this->metrics), 0.0001);
    }

    // ─── ZERO REGRESSION: null-safe fallback preserved ─────────────────

    public function testFallsBackToMechanicalOnLlmFailure(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willThrowException(new \RuntimeException('timeout'));
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.7);

        self::assertSame(0.42, $judge->hybrid(0.42, $this->messages, $this->metrics));
    }

    public function testFallsBackToMechanicalOnUnparseableResponse(): void
    {
        $llm = $this->createMock(LLMClientInterface::class);
        $llm->method('chat')->willReturn('not json at all');
        $judge = new RewardJudge($llm, new NullLogger(), new PromptProvider('/nonexistent-prompt-dir', new NullLogger()), 0.7);

        self::assertSame(0.33, $judge->hybrid(0.33, $this->messages, $this->metrics));
    }
}
