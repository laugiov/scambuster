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
        private LoggerInterface $logger,
        // Spec 080 §2 — when false, the validator ignores the $context
        // parameter even if passed. Default true; set false via
        // REPLY_VALIDATOR_CONTEXT_ENABLED env var for rollback.
        private bool $validatorContextEnabled = true,
    ) {
    }

    /**
     * Validate a generated reply text using LLM multi-criteria judge.
     *
     * Returns a legacy array for backward compatibility.
     * Use validateMultiCriteria() for the full ValidationResult.
     *
     * @param string                    $text        Generated reply text to validate
     * @param string                    $personaCode Persona code for validation context
     * @param array<string, mixed>|null $context     Spec 080 §2 — optional conversational
     *                                               context. Shape documented in
     *                                               spec 080 §2. When null or when
     *                                               $validatorContextEnabled is false,
     *                                               legacy 2-arg behavior is preserved.
     *
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     *
     * Spec 095 Fix D — return array also includes score fields (naturalness,
     * persona_fit, ti_value, security_pass) via ValidationResult::toLegacyArray().
     *
     * @return array{approved: bool, reasons: array<string>, fix_suggestion: string|null, correction: array{problem_span: string, replacement: string, rationale: string}|null, naturalness: int, persona_fit: int, ti_value: int, security_pass: bool}
     */
    public function validate(string $text, string $personaCode, ?array $context = null): array
    {
        return $this->validateMultiCriteria($text, $personaCode, $context)->toLegacyArray();
    }

    /**
     * Validate a generated reply with multi-criteria scoring.
     *
     * @param array<string, mixed>|null $context Spec 080 §2 — see validate()
     *
     * @throws \RuntimeException If LLM call fails or returns invalid JSON
     */
    public function validateMultiCriteria(string $text, string $personaCode, ?array $context = null): ValidationResult
    {
        // Spec 080 §2 — effective context: passed only when the flag is on.
        $effectiveContext = $this->validatorContextEnabled ? $context : null;

        $this->logger->debug('[ReplyValidator] Building validation prompts', [
            'persona' => $personaCode,
            'text_length' => strlen($text),
            'context_provided' => $context !== null,
            'context_effective' => $effectiveContext !== null,
        ]);

        if ($effectiveContext !== null) {
            $this->logger->debug('[ReplyValidator] context received', [
                'persona' => $personaCode,
                'context_keys' => array_keys($effectiveContext),
            ]);
        }

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
            /** @var array<string, mixed> $data */

            // Support both legacy and multi-criteria format.
            // Spec 080 §3 — pass $text so StructuredCorrection can validate
            // problem_span against the actual generated text.
            $result = $this->parseValidatorResponse($data, $text);

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
     * @param string               $generatedText The text the validator was scoring.
     *                                            Passed to ValidationResult so a
     *                                            structured correction (if any) can
     *                                            be validated against the original.
     */
    private function parseValidatorResponse(array $data, string $generatedText): ValidationResult
    {
        // Multi-criteria format (new): has naturalness, persona_fit, ti_value
        if (isset($data['naturalness'])) {
            return ValidationResult::fromLLMResponse($data, $generatedText);
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
            reasons: array_map(static fn (mixed $r): string => \is_string($r) ? $r : '', $reasons),
            fixSuggestion: isset($data['fix_suggestion']) && \is_string($data['fix_suggestion']) ? $data['fix_suggestion'] : null,
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
