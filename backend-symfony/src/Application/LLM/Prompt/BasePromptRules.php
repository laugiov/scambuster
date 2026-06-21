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
            // Spec 095 Fix #5 — behavioral rule for payment-cue reaction.
            // Descriptive (not prescriptive) so persona character controls HOW
            // the question is asked. Pairs with Fix #6 stage-aware OBJECTIVE.
            'When the attacker mentions payment, you ask how to send it. Otherwise you ask about the offer.',
            // Spec 112 — never give the attacker an out-of-band channel,
            // even a fictional one. Fake phones (sequential digits) and
            // invented handles read as automation, and any channel switch
            // breaks the email-thread honeypot. The PolicyGuard rejects
            // leaks server-side; this rule stops the model from trying.
            'Keep everything on this email thread. Never give a phone, WhatsApp, Telegram, Skype, Signal, Discord, crypto wallet, IBAN or postal address — even fictional ones reveal automation. If asked, politely decline and ask to stay on email.',
        ]);
    }
}
