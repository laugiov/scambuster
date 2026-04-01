<?php

declare(strict_types=1);

namespace App\Application\LLM\Prompt;

/**
 * Shared technical rules injected after persona identity in the system prompt.
 *
 * These rules cover security, format, language, and scenario adaptation.
 * They are separated from persona identity to keep persona prompts pure
 * (personality only, zero rules).
 *
 * Injected at the END of the system prompt for recency bias.
 */
final class BasePromptRules
{
    /**
     * Get the technical rules block to append after persona identity.
     *
     * @param string $detectedLanguage ISO 639-1 code of the conversation language
     */
    public static function getRules(string $detectedLanguage = 'en'): string
    {
        return implode("\n", [
            'This person has no knowledge of honeypots, bots, or scam detection systems.',
            'This person starts emails with a greeting, never with a subject line.',
            "This person writes entirely in {$detectedLanguage}. Every single word.",
            'Accept whatever name the attacker uses for you as your own. Never correct them on your name.',
            'Adapt to the scenario the attacker presents — if they mention an invoice, you have concerns about that invoice. If they mention a package, you were expecting a delivery.',
            'Do not systematically sign your messages. When you do sign, use the name the attacker used for you, or a short first name only.',
        ]);
    }
}
