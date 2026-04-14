<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Validation\ValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Reply Validator - LLM-based multi-criteria validation
 *
 * Uses a separate LLM call to validate generated replies against
 * 3 quality dimensions (naturalness, persona_fit, ti_value) + security gate.
 *
 * Rejection logic:
 * - security_pass = false → reject
 * - naturalness < 2 → reject
 * - average(naturalness, persona_fit, ti_value) < 2.5 → reject
 *
 * Returns ValidationResult (with backward-compatible toLegacyArray()).
 */
final readonly class ReplyValidator
{
    public function __construct(
        private LLMClientInterface $llmClient,
        private PromptBuilder $promptBuilder,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Validate a generated reply text using LLM multi-criteria judge.
     *
     * Returns a legacy array for backward compatibility.
     * Use validateMultiCriteria() for the full ValidationResult.
     *
     * @param string $text        Generated reply text to validate
     * @param string $personaCode Persona code for validation context
     *
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     *
     * @return array{approved: bool, reasons: array<string>, fix_suggestion: string|null}
     */
    public function validate(string $text, string $personaCode): array
    {
        return $this->validateMultiCriteria($text, $personaCode)->toLegacyArray();
    }

    /**
     * Validate a generated reply with multi-criteria scoring.
     *
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     */
    public function validateMultiCriteria(string $text, string $personaCode): ValidationResult
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
            'temperature' => 0.4,
            'max_tokens' => 500,
            'purpose' => 'reply_validation',
        ];

        $this->logger->debug('[ReplyValidator] Calling LLM validator', [
            'persona' => $personaCode,
            'model' => 'gpt-4o-mini',
            'temperature' => $options['temperature'],
            'max_tokens' => $options['max_tokens'],
        ]);

        try {
            $response = $this->llmClient->chat($messages, $options);

            $this->logger->debug('[ReplyValidator] LLM validator response', [
                'persona' => $personaCode,
                'response_length' => strlen($response),
                'raw_response' => $response,
            ]);

            $jsonText = $this->extractJson($response);
            $data = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                throw new \RuntimeException('Invalid validator response: not a JSON object');
            }

            // Support both legacy and multi-criteria format
            $result = $this->parseValidatorResponse($data);

            $this->logger->info('[ReplyValidator] Validation completed', [
                'approved' => $result->approved,
                'naturalness' => $result->naturalness,
                'persona_fit' => $result->personaFit,
                'ti_value' => $result->tiValue,
                'security_pass' => $result->securityPass,
                'average_quality' => $result->averageQualityScore(),
                'persona' => $personaCode,
            ]);

            return $result;
        } catch (\JsonException $e) {
            $this->logger->error('LLM validator returned invalid JSON', [
                'error' => $e->getMessage(),
                'response' => $response,
            ]);

            throw new \RuntimeException("Validator returned invalid JSON: {$e->getMessage()}", $e->getCode(), previous: $e);
        }
    }

    /**
     * Parse validator response, supporting both multi-criteria and legacy formats.
     *
     * @param array<string, mixed> $data
     */
    private function parseValidatorResponse(array $data): ValidationResult
    {
        // Multi-criteria format (new): has naturalness, persona_fit, ti_value
        if (isset($data['naturalness'])) {
            return ValidationResult::fromLLMResponse($data);
        }

        // Legacy format: has approved + reasons
        if (!isset($data['approved']) || !is_bool($data['approved'])) {
            throw new \RuntimeException('Invalid validator response: missing or invalid "approved" field');
        }

        $reasons = $data['reasons'] ?? [];

        if (!is_array($reasons)) {
            throw new \RuntimeException('Invalid validator response: "reasons" must be an array');
        }

        // Convert legacy to ValidationResult with default scores
        return new ValidationResult(
            approved: $data['approved'],
            naturalness: $data['approved'] ? 3 : 1,
            personaFit: $data['approved'] ? 3 : 1,
            tiValue: $data['approved'] ? 3 : 1,
            securityPass: $data['approved'],
            feedback: implode('; ', $reasons),
            reasons: $reasons,
            fixSuggestion: $data['fix_suggestion'] ?? null,
        );
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
