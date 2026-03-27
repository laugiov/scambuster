<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Context-aware word count thresholds for PolicyGuard.
 *
 * Adjusts min/max word limits based on conversational context flags
 * from ConversationAnalyzer (bot accusation, aggression, post-IBAN, etc.).
 *
 * Solves the mismatch where ConversationAnalyzer recommends 30-40 word
 * replies for bot accusations but PolicyGuard rejects anything under 50.
 */
final class PolicyGuardConfig
{
    public function __construct(
        public readonly int $minWords,
        public readonly int $maxWords,
    ) {
    }

    /**
     * Build config from conversation context flags.
     *
     * @param array<string, mixed> $context Conversation context with optional flags:
     *                                      - is_bot_accusation (bool): Scammer accused the system of being a bot
     *                                      - is_aggression (bool): Scammer is aggressive/threatening
     *                                      - is_post_iban (bool): IBAN was already captured in this conversation
     *                                      - is_evasive_scammer (bool): Scammer ignored last 2+ requests
     *                                      - tone_recommendation (string): From ConversationAnalyzer
     */
    public static function fromContext(array $context): self
    {
        $toneRecommendation = $context['tone_recommendation'] ?? '';
        $isBotAccusation = ($context['is_bot_accusation'] ?? false)
            || $toneRecommendation === 'déstabilisé';
        $isAggression = ($context['is_aggression'] ?? false)
            || $toneRecommendation === 'offensé';
        $isPostIban = $context['is_post_iban'] ?? false;
        $isEvasiveScammer = $context['is_evasive_scammer'] ?? false;

        return match (true) {
            $isBotAccusation => new self(minWords: 20, maxWords: 70),
            $isAggression => new self(minWords: 25, maxWords: 90),
            $isPostIban => new self(minWords: 30, maxWords: 100),
            $isEvasiveScammer => new self(minWords: 30, maxWords: 120),
            default => new self(minWords: 35, maxWords: 150),
        };
    }

    /**
     * Default config for normal conversations (backward compatible).
     */
    public static function default(): self
    {
        return new self(minWords: 35, maxWords: 150);
    }
}
