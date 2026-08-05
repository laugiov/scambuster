<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Manages give/take balance in conversation to maintain credibility
 *
 * Analyzes conversation flow to determine when the victim should:
 * - Give information (fake phone, fake name, fake situation details)
 * - Answer direct questions instead of asking more questions
 * - Show empathy and reciprocity
 *
 * Prevents the bot from sounding like an interrogator by ensuring
 * natural back-and-forth exchange.
 */
final readonly class ReciprocityManager
{
    public function __construct(
        private ?\Psr\Log\LoggerInterface $logger = null,
    ) {
    }

    /**
     * Emotional keywords indicating attacker vulnerability
     */
    private const EMOTIONAL_KEYWORDS = [
        'désespéré',
        'peur',
        'seul',
        'panique',
        'angoisse',
        'piège',
        'aide-moi',
        'supplie',
        'besoin de toi',
        'compte sur toi',
        'urgent',
        'critique',
    ];

    /**
     * Direct question patterns that require direct answers
     */
    private const DIRECT_QUESTION_PATTERNS = [
        '/quel(?:le)?\s+(?:est|sont)\s+(?:ton|votre)/i',
        '/(?:donne|file|envoie|passe)[-\s]moi/i',
        '/tu peux (?:me donner|m\'envoyer)/i',
        '/j\'ai besoin (?:de|d\')/i',
        '/(?:dis|dites)[-\s]moi/i',
    ];

    /**
     * Analyze conversation and determine reciprocity instructions
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return array{
     *     should_give_info: bool,
     *     reason: string,
     *     suggested_action: string,
     *     give_count: int,
     *     take_count: int
     * }
     */
    public function analyze(array $messages): array
    {
        if ($messages === []) {
            return [
                'should_give_info' => false,
                'reason' => 'first_message',
                'suggested_action' => '',
                'give_count' => 0,
                'take_count' => 0,
            ];
        }

        $giveCount = $this->countVictimGives($messages);
        $takeCount = $this->countVictimTakes($messages);
        $lastAttackerMsg = $this->getLastAttackerMessage($messages);

        // Rule 1: If we've asked 2+ times without giving anything → GIVE
        if ($takeCount >= 2 && $giveCount === 0) {
            return [
                'should_give_info' => true,
                'reason' => 'too_many_questions',
                'suggested_action' => 'You have asked several questions. Now share something small in return to keep things balanced: a first name or a little personal detail. Match your correspondent tone.',
                'give_count' => $giveCount,
                'take_count' => $takeCount,
            ];
        }

        // Rule 2: If attacker shows strong emotions → GIVE empathy + info
        if ($this->hasStrongEmotions($lastAttackerMsg)) {
            return [
                'should_give_info' => true,
                'reason' => 'emotional_vulnerability',
                'suggested_action' => 'Your correspondent seems stressed or in a hurry. Show empathy and offer help with a concrete detail (a first name). Match your correspondent tone.',
                'give_count' => $giveCount,
                'take_count' => $takeCount,
            ];
        }

        // Rule 3: If attacker asks direct question → ANSWER directly
        if ($this->hasDirectQuestion($lastAttackerMsg)) {
            return [
                'should_give_info' => true,
                'reason' => 'direct_question',
                'suggested_action' => 'A direct question was asked. Answer it clearly before possibly asking another. Match your correspondent tone.',
                'give_count' => $giveCount,
                'take_count' => $takeCount,
            ];
        }

        // Rule 4: If ratio take/give > 2 → GIVE
        if ($giveCount > 0 && ($takeCount / $giveCount) > 2) {
            return [
                'should_give_info' => true,
                'reason' => 'imbalanced_ratio',
                'suggested_action' => 'Balance the conversation: you have asked a lot, now give something too (a first name, your situation). Match your correspondent tone.',
                'give_count' => $giveCount,
                'take_count' => $takeCount,
            ];
        }

        // Default: No need to give yet
        $result = [
            'should_give_info' => false,
            'reason' => 'balanced',
            'suggested_action' => '',
            'give_count' => $giveCount,
            'take_count' => $takeCount,
        ];

        $this->logger?->debug('[ReciprocityManager] Analysis', [
            'should_give_info' => $result['should_give_info'],
            'reason' => $result['reason'],
            'give_count' => $giveCount,
            'take_count' => $takeCount,
        ]);

        return $result;
    }

    /**
     * Count how many times victim gave information
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function countVictimGives(array $messages): int
    {
        $count = 0;

        foreach ($messages as $msg) {
            if ($msg['direction'] === 'out') {
                $text = mb_strtolower($msg['body_text']);

                // Check for giving patterns
                if (
                    preg_match('/voici mon/i', $text) ||
                    preg_match('/(?:mon|ma)\s+(?:numéro|nom|prénom|adresse|email)/i', $text) ||
                    preg_match('/je m\'appelle/i', $text) ||
                    preg_match('/tu peux m\'appeler/i', $text)
                ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Count how many times victim asked for information
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function countVictimTakes(array $messages): int
    {
        $count = 0;

        foreach ($messages as $msg) {
            if ($msg['direction'] === 'out') {
                $text = $msg['body_text'];

                // Count questions asked
                $questionCount = substr_count($text, '?');
                $count += $questionCount;

                // Count explicit asks
                if (
                    preg_match('/(?:peux|pourrais)[-\s]tu me (?:donner|dire|expliquer)/i', $text) ||
                    preg_match('/j\'aurais besoin de/i', $text) ||
                    preg_match('/(?:donne|file|envoie)[-\s]moi/i', $text)
                ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get last attacker message
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     */
    private function getLastAttackerMessage(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if ($messages[$i]['direction'] === 'in') {
                return $messages[$i]['body_text'];
            }
        }

        return '';
    }

    /**
     * Check if text contains strong emotional keywords
     */
    private function hasStrongEmotions(string $text): bool
    {
        $textLower = mb_strtolower($text);

        foreach (self::EMOTIONAL_KEYWORDS as $keyword) {
            if (str_contains($textLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if text contains direct question requiring answer
     */
    private function hasDirectQuestion(string $text): bool
    {
        foreach (self::DIRECT_QUESTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate fake data suggestions for the LLM
     */
    public function generateFakeDataSuggestions(): string
    {
        $suggestions = "\nSOFT DETAILS YOU MAY SHARE IF IT HELPS:\n\n";
        $suggestions .= "If the situation calls for it, invent and share believable, harmless personal details (a first name, a city, a vague professional situation) to keep the exchange balanced.\n";

        return $suggestions . "RULE: keep these details natural and consistent with the context. Never share a phone number, messaging handle, wallet or any contact channel. Match your correspondent tone.\n";
    }
}
