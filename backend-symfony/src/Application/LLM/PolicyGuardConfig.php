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
final readonly class PolicyGuardConfig
{
    public function __construct(
        public int $minWords,
        public int $maxWords,
    ) {
    }

    /**
     * Persona-tone keywords that mark a terse archetype. The persona's
     * free-text tone descriptor is the same field the style validator's
     * persona-fit expectations come from — reusing it keeps one source
     * of truth, so the validator can never demand a brevity the length
     * floor forbids.
     *
     * `short` is intentional: it matches the elderly persona whose tone
     * is "Short, affectionate, family-focused", a genuinely brief writer.
     * It only lowers the minimum (the maximum is unchanged), so an
     * incidental future match can at worst allow a curter reply, never a
     * non-compliant one.
     */
    private const TERSE_TONE_PATTERN = '/\b(?:telegraphic|concise|abbreviated|terse|short)\b/i';

    /**
     * Build config from conversation context flags.
     *
     * @param array<string, mixed> $context Conversation context with optional flags:
     *                                      - is_bot_accusation (bool): Scammer accused the system of being a bot
     *                                      - is_aggression (bool): Scammer is aggressive/threatening
     *                                      - is_post_iban (bool): IBAN was already captured in this conversation
     *                                      - is_evasive_scammer (bool): Scammer ignored last 2+ requests
     *                                      - tone_recommendation (string): From ConversationAnalyzer
     *                                      - persona_tone (string): Persona's free-text tone descriptor
     */
    public static function fromContext(array $context): self
    {
        $toneRecommendation = $context['tone_recommendation'] ?? '';
        $isBotAccusation = ($context['is_bot_accusation'] ?? false)
            || $toneRecommendation === 'unsettled';
        $isAggression = ($context['is_aggression'] ?? false)
            || $toneRecommendation === 'offended';
        $isPostIban = $context['is_post_iban'] ?? false;
        $isEvasiveScammer = $context['is_evasive_scammer'] ?? false;

        $personaTone = $context['persona_tone'] ?? '';
        $isTersePersona = \is_string($personaTone)
            && preg_match(self::TERSE_TONE_PATTERN, $personaTone) === 1;

        // Situational overrides keep precedence over the persona archetype.
        // Floors are deliberately low: a human writes short replies too, and a
        // hard word-count floor forcing every reply to be long is itself a bot
        // tell. Situational bands stay at or below the default so those contexts
        // never demand MORE length than a normal turn.
        return match (true) {
            $isBotAccusation => new self(minWords: 12, maxWords: 70),
            $isAggression => new self(minWords: 15, maxWords: 90),
            $isPostIban => new self(minWords: 18, maxWords: 100),
            $isEvasiveScammer => new self(minWords: 18, maxWords: 120),
            $isTersePersona => new self(minWords: 12, maxWords: 120),
            default => new self(minWords: 20, maxWords: 150),
        };
    }

    /**
     * Default config for normal conversations (backward compatible).
     */
    public static function default(): self
    {
        return new self(minWords: 20, maxWords: 150);
    }
}
