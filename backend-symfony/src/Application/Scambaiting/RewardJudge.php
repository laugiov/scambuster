<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\Prompt\PromptCatalog;
use App\Application\LLM\Prompt\PromptProvider;
use App\Domain\Scambaiting\ConversationMetrics;
use Psr\Log\LoggerInterface;

/**
 * Judges the real OUTCOME of a finished conversation with an LLM and blends it
 * with the mechanical metric reward, so the bandit learns from what actually
 * happened rather than from proxies (duration, raw IOC counts) that a long,
 * fruitless exchange can inflate.
 *
 * Hybrid by design: the LLM outcome score is the dominant term, with the
 * mechanical reward kept as a stabilising floor. Fully null-safe — any LLM or
 * parsing failure returns the mechanical reward unchanged, so scoring never
 * blocks a closure.
 */
final readonly class RewardJudge
{
    private const MAX_MESSAGES = 16;

    public function __construct(
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
        private PromptProvider $promptProvider,
        /** Weight of the LLM outcome score in the blend (0..1); the remainder is the mechanical reward. */
        private float $llmWeight = 0.7,
    ) {
    }

    /**
     * Blend the mechanical reward with an LLM outcome judgement.
     *
     * @param list<array{direction: string, body_text: string}> $messages
     *
     * @return float reward in [0.0, 1.0]
     */
    public function hybrid(float $mechanicalReward, array $messages, ConversationMetrics $metrics): float
    {
        $mechanicalReward = max(0.0, min(1.0, $mechanicalReward));

        $outcome = $this->judgeOutcome($messages, $metrics);

        if ($outcome === null) {
            return $mechanicalReward;
        }

        $blended = ($this->llmWeight * $outcome) + ((1.0 - $this->llmWeight) * $mechanicalReward);

        return max(0.0, min(1.0, $blended));
    }

    /**
     * @param list<array{direction: string, body_text: string}> $messages
     *
     * @return float|null outcome score in [0.0, 1.0], or null on any failure
     */
    private function judgeOutcome(array $messages, ConversationMetrics $metrics): ?float
    {
        if ($messages === []) {
            return null;
        }

        $transcript = $this->formatTranscript($messages);

        // Operator override resolves under config/scambuster/prompts/reward_judge.txt;
        // absent or empty → the shipped default below. Lets an operator redefine what
        // "a good outcome" means for their mission (which re-points the bandit target).
        // The rubric is a static system prompt — no placeholders to preserve.
        $defaultRubric = PromptCatalog::defaultBody('reward_judge');

        $systemPrompt = $this->promptProvider->resolve('reward_judge', [], $defaultRubric);

        $userPrompt = sprintf(
            "Signals: iocs_total=%d, high_value_iocs=%d, completed=%s.\n\nTranscript:\n%s",
            $metrics->getIocsTotal(),
            $metrics->getIocsSensibles(),
            $metrics->isCompleted() ? 'yes' : 'no',
            $transcript,
        );

        try {
            $response = $this->llmClient->chat(
                [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                ['response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('[RewardJudge] LLM outcome scoring failed, using mechanical reward', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $this->parseScore($response);
    }

    private function parseScore(string $response): ?float
    {
        if (preg_match('/\{.*\}/s', $response, $m) !== 1) {
            return null;
        }

        try {
            $decoded = json_decode($m[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || !isset($decoded['outcome_score']) || !is_numeric($decoded['outcome_score'])) {
            return null;
        }

        return max(0.0, min(1.0, (float) $decoded['outcome_score']));
    }

    /**
     * @param list<array{direction: string, body_text: string}> $messages
     */
    private function formatTranscript(array $messages): string
    {
        $recent = array_slice($messages, -self::MAX_MESSAGES);
        $lines = [];

        foreach ($recent as $msg) {
            $who = $msg['direction'] === 'out' ? 'PERSONA' : 'SCAMMER';
            $body = trim(preg_replace('/\s+/', ' ', $msg['body_text']) ?? '');
            $lines[] = $who . ': ' . mb_substr($body, 0, 500);
        }

        return implode("\n", $lines);
    }
}
