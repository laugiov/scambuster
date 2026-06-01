<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Scores generated replies based on their likelihood to extract IOCs
 *
 * Implements a heuristic scoring system (0-100) to evaluate if a generated
 * reply is likely to be useful for IOC collection. This helps filter out
 * low-quality responses that wouldn't contribute to honeypot intelligence.
 *
 * Scoring criteria:
 * - +25: Contains explicit question (?)
 * - +25: Targets specific channel (phone, whatsapp, url, iban)
 * - +15: References last attacker message
 * - +10: Mentions missing IOC types
 * - -20: Proposes proactive action (might alert attacker)
 * - -10: Repeats question from context
 * - -15: Generic/vague language
 */
final class IOCLikelihoodScorer
{
    /**
     * Channel-related keywords for detection
     *
     * Spec 095 Fix #7 — extended with EN keywords (additive, FR detection
     * preserved). Pre-Fix #7 the FR-only list missed scoring channels on
     * the 90% EN corpus. See:
     * specs/095-pipeline-audit/fix-07-i18n-ioc-scorer/spec.md
     */
    private const CHANNEL_KEYWORDS = [
        'phone' => [
            // FR
            'téléphone', 'numéro', 'appeler', 'joindre', 'contacter', 'mobile',
            // EN (Spec 095 Fix #7)
            'phone', 'number', 'call', 'reach', 'cell',
        ],
        'whatsapp' => ['whatsapp', 'telegram', 'signal'],
        'url' => [
            // FR
            'lien', 'site', 'page', 'url', 'adresse web',
            // EN (Spec 095 Fix #7)
            'link', 'website',
        ],
        'iban' => [
            // FR
            'iban', 'compte', 'virement', 'transfert', 'bancaire', 'rib',
            // EN (Spec 095 Fix #7)
            'account', 'wire', 'bank', 'sort code', 'routing', 'beneficiary',
        ],
        'email' => ['email', 'e-mail', 'mail', 'courriel'],
        'crypto' => [
            // FR + universal
            'bitcoin', 'crypto', 'portefeuille', 'wallet',
            // EN (Spec 095 Fix #7)
            'btc', 'eth',
        ],
    ];

    /**
     * Proactive action patterns (should be avoided)
     *
     * Spec 095 Fix #7 — extended with EN regex (additive).
     */
    private const PROACTIVE_PATTERNS = [
        // FR
        '/je vais (vérifier|contacter|appeler)/i',
        '/je peux (vous envoyer|transmettre|fournir)/i',
        '/voici (mon|mes)/i',
        '/je vous (envoie|transmets|fournis)/i',
        // EN (Spec 095 Fix #7)
        '/I will (check|contact|call|verify)/i',
        '/I can (send|share|provide|give)/i',
        '/here (is|are) my/i',
        '/let me send you/i',
    ];

    /**
     * Generic/vague phrases that reduce score
     *
     * Spec 095 Fix #7 — extended with EN phrases (additive).
     */
    private const GENERIC_PHRASES = [
        // FR
        'je comprends',
        'je vois',
        'c\'est inquiétant',
        'je suis préoccupé',
        'merci pour votre message',
        // EN (Spec 095 Fix #7)
        'i understand',
        'i see',
        'thank you for your message',
        'thank you for reaching out',
        'i appreciate',
    ];

    /**
     * Score a generated reply based on IOC extraction likelihood
     *
     * @param string               $generatedText The generated reply text
     * @param array<string, mixed> $context       Conversation context including state slots
     *
     * @return int Score from 0 to 100
     */
    public function score(string $generatedText, array $context): int
    {
        $score = 0;
        $textLower = mb_strtolower($generatedText);

        // +25 if contains explicit question
        if ($this->containsExplicitQuestion($generatedText)) {
            $score += 25;
        }

        // +25 if targets specific channel
        if ($this->targetsSpecificChannel($textLower, $context)) {
            $score += 25;
        }

        // +15 if references last attacker message
        if ($this->referencesLastMessage($textLower, $context)) {
            $score += 15;
        }

        // +10 if mentions missing IOC types
        if ($this->mentionsMissingIOCs($textLower, $context)) {
            $score += 10;
        }

        // -20 if proposes proactive action
        if ($this->proposesProactiveAction($generatedText)) {
            $score -= 20;
        }

        // -10 if repeats question from context
        if ($this->repeatsQuestion($textLower, $context)) {
            $score -= 10;
        }

        // -15 if uses generic/vague language
        if ($this->usesGenericLanguage($textLower)) {
            $score -= 15;
        }

        // Clamp score between 0 and 100
        return max(0, min(100, $score));
    }

    /**
     * Check if text contains an explicit question
     */
    private function containsExplicitQuestion(string $text): bool
    {
        return str_contains($text, '?');
    }

    /**
     * Check if text targets a specific communication channel
     */
    /**
     * @param array<string, mixed> $context
     */
    private function targetsSpecificChannel(string $textLower, array $context): bool
    {
        // Get canal_cible from context if available
        /** @var array<string, mixed> $stateSlots */
        $stateSlots = $context['state_slots'] ?? [];
        $canalCible = $stateSlots['canal_cible'] ?? null;

        // Check if text mentions the target channel keywords
        foreach (self::CHANNEL_KEYWORDS as $channel => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($textLower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if text references content from last attacker message
     */
    /**
     * @param array<string, mixed> $context
     */
    private function referencesLastMessage(string $textLower, array $context): bool
    {
        /** @var array<int, array{direction: string, body_text: string}> $lastMessages */
        $lastMessages = $context['last_messages'] ?? [];

        // Find last attacker message
        $lastAttackerMsg = null;

        for ($i = count($lastMessages) - 1; $i >= 0; $i--) {
            if ($lastMessages[$i]['direction'] === 'in') {
                $lastAttackerMsg = $lastMessages[$i];

                break;
            }
        }

        if (!$lastAttackerMsg) {
            return false;
        }

        $attackerTextLower = mb_strtolower($lastAttackerMsg['body_text']);

        // Extract keywords from attacker message (words >4 chars, excluding common words)
        $commonWords = ['pour', 'votre', 'vous', 'avec', 'dans', 'cette', 'plus', 'tout', 'tous', 'faire', 'être', 'avoir'];
        $attackerWords = preg_split('/\s+/', $attackerTextLower) ?: [];
        $significantWords = array_filter($attackerWords, fn ($w): bool => strlen((string) $w) > 4 && !in_array($w, $commonWords, true));

        // Check if reply references at least one significant word
        foreach ($significantWords as $word) {
            if (str_contains($textLower, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text mentions missing IOC types
     */
    /**
     * @param array<string, mixed> $context
     */
    private function mentionsMissingIOCs(string $textLower, array $context): bool
    {
        /** @var array<string, mixed> $stateSlots */
        $stateSlots = $context['state_slots'] ?? [];
        /** @var array<string> $missingIOCs */
        $missingIOCs = $stateSlots['missing_iocs'] ?? [];

        foreach ($missingIOCs as $iocType) {
            $keywords = self::CHANNEL_KEYWORDS[$iocType] ?? [];

            foreach ($keywords as $keyword) {
                if (str_contains($textLower, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if text proposes proactive action (bad for honeypot)
     */
    private function proposesProactiveAction(string $text): bool
    {
        foreach (self::PROACTIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text repeats a question already asked
     */
    /**
     * @param array<string, mixed> $context
     */
    private function repeatsQuestion(string $textLower, array $context): bool
    {
        /** @var array<int, array{direction: string, body_text: string}> $lastMessages */
        $lastMessages = $context['last_messages'] ?? [];

        // Extract questions from previous victim messages
        foreach ($lastMessages as $msg) {
            if ($msg['direction'] === 'out') {
                $previousTextLower = mb_strtolower($msg['body_text']);

                // Extract question patterns
                if (preg_match('/quel(?:le|s)?\s+(\w+)/i', $previousTextLower, $prevMatches) && preg_match('/quel(?:le|s)?\s+(\w+)/i', $textLower, $currMatches) && $prevMatches[1] === $currMatches[1]) {
                    return true;
                    // Same question pattern
                }
            }
        }

        return false;
    }

    /**
     * Check if text uses generic/vague language
     */
    private function usesGenericLanguage(string $textLower): bool
    {
        $genericCount = 0;

        foreach (self::GENERIC_PHRASES as $phrase) {
            if (str_contains($textLower, $phrase)) {
                $genericCount++;
            }
        }

        // Consider generic if 2+ generic phrases used
        return $genericCount >= 2;
    }
}
