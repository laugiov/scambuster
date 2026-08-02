<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Provides variation instructions to avoid repetitive patterns
 *
 * Passes previous victim messages to the LLM so it can avoid repetition.
 * No PHP-based pattern detection - the LLM handles all variation logic.
 */
final class VariationProvider
{
    /**
     * Generate anti-repetition instructions based on conversation history
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return string Formatted instructions for the LLM
     */
    public function generateInstructions(array $messages): string
    {
        $victimMessages = $this->extractVictimMessages($messages);

        if (count($victimMessages) < 2) {
            return ''; // Not enough history to worry about repetition
        }

        return $this->formatInstructions($victimMessages);
    }

    /**
     * Extract only victim messages (direction='out')
     *
     * @param array<int, array{direction: string, body_text: string}> $messages
     *
     * @return array<int, string>
     */
    private function extractVictimMessages(array $messages): array
    {
        $victimMessages = [];

        foreach ($messages as $msg) {
            if ($msg['direction'] === 'out') {
                $victimMessages[] = $msg['body_text'];
            }
        }

        return $victimMessages;
    }

    /**
     * Format anti-repetition instructions for the LLM
     *
     * @param array<int, string> $victimMessages
     */
    private function formatInstructions(array $victimMessages): string
    {
        $instructions = "\nANTI-REPETITION — YOUR PREVIOUS MESSAGES:\n\n";

        // Show last 3 messages to give context
        $recentMessages = array_slice($victimMessages, -3);

        foreach ($recentMessages as $index => $message) {
            $messageNumber = count($victimMessages) - count($recentMessages) + $index + 1;
            $instructions .= "Message #{$messageNumber}:\n{$message}\n\n";
        }

        $instructions .= "CRITICAL RULE:\n";
        $instructions .= "- Do NOT repeat the same keywords, expressions or sentence patterns\n";
        $instructions .= "- Vary your vocabulary and structures every time\n";
        $instructions .= "- Do NOT reuse the same closing formulas\n";

        return $instructions . "- Stay natural but CHANGE with every message\n";
    }
}
