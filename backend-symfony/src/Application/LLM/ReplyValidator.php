<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Reply Validator - LLM-based semantic validation
 *
 * Uses a separate LLM call to validate generated replies against
 * persona coherence, tone, and security criteria.
 *
 * Returns structured JSON verdict: {approved, reasons, fix_suggestion}
 */
final class ReplyValidator
{
    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly PromptBuilder $promptBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Validate a generated reply text using LLM judge
     *
     * @param string             $text             Generated reply text to validate
     * @param string             $personaCode      Persona code for validation context
     * @param array<string>|null $previousMessages Previous victim messages (unused, kept for backward compatibility)
     *
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     *
     * @return array{approved: bool, reasons: array<string>, fix_suggestion: string|null}
     */
    public function validate(string $text, string $personaCode, ?array $previousMessages = null): array
    {
        $this->logger->debug('[ReplyValidator] Building validation prompts', [
            'persona' => $personaCode,
            'text_length' => strlen($text),
        ]);

        $prompts = $this->promptBuilder->buildValidatorPrompts($text, $personaCode);

        $messages = [
            ['role' => 'system', 'content' => $prompts['system']],
            ['role' => 'user', 'content' => $prompts['user']],
        ];

        $options = [
            'temperature' => 0.1, // Low temperature for consistent validation
            'max_tokens' => 300,
            'purpose' => 'reply_validation',
        ];

        $this->logger->debug('[ReplyValidator] 📤 CALLING LLM VALIDATOR', [
            'persona' => $personaCode,
            'model' => 'gpt-4o-mini',
            'temperature' => $options['temperature'],
            'max_tokens' => $options['max_tokens'],
            'system_prompt_length' => strlen($prompts['system']),
            'user_prompt_length' => strlen($prompts['user']),
            'system_prompt' => $prompts['system'],
            'user_prompt' => $prompts['user'],
        ]);

        try {
            $response = $this->llmClient->chat($messages, $options);

            $this->logger->debug('[ReplyValidator] 📥 LLM VALIDATOR RAW RESPONSE', [
                'persona' => $personaCode,
                'response_length' => strlen($response),
                'raw_response' => $response,
            ]);

            // Extract JSON from response (handle markdown code blocks)
            $jsonText = $this->extractJson($response);

            $verdict = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($verdict)) {
                throw new \RuntimeException('Invalid validator response: not a JSON object');
            }

            // Validate verdict structure
            if (!isset($verdict['approved']) || !is_bool($verdict['approved'])) {
                throw new \RuntimeException('Invalid validator response: missing or invalid "approved" field');
            }

            $reasons = $verdict['reasons'] ?? [];

            if (!is_array($reasons)) {
                throw new \RuntimeException('Invalid validator response: "reasons" must be an array');
            }

            $this->logger->info('[ReplyValidator] ✅ Validation completed', [
                'approved' => $verdict['approved'],
                'reasons_count' => count($reasons),
                'reasons' => $reasons,
                'fix_suggestion' => $verdict['fix_suggestion'] ?? null,
                'persona' => $personaCode,
            ]);

            return [
                'approved' => $verdict['approved'],
                'reasons' => $reasons,
                'fix_suggestion' => $verdict['fix_suggestion'] ?? null,
            ];
        } catch (\JsonException $e) {
            $this->logger->error('LLM validator returned invalid JSON', [
                'error' => $e->getMessage(),
                'response' => $response,
            ]);

            throw new \RuntimeException(
                "Validator returned invalid JSON: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Extract JSON from LLM response (handles markdown code blocks)
     */
    private function extractJson(string $response): string
    {
        // Remove markdown code blocks if present
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        // Try to find JSON object in response
        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }
}
