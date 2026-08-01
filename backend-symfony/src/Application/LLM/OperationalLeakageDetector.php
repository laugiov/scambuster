<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Validation\LeakageDetectionResult;
use Psr\Log\LoggerInterface;

/**
 * Second-LLM operational leakage detector.
 *
 * Runs after PolicyGuard approves and before the existing ReplyValidator.
 * Asks a fresh LLM call (gpt-4o-mini, T=0.0) whether the generated text
 * leaks operational information about the platform — including
 * paraphrased mentions like "the orchestrator", "the platform that runs
 * me", or instruction-injection attempts.
 *
 * Defensive: any failure (LLM exception, JSON parse error, malformed
 * response) returns leakDetected=false. The hard gate is the PolicyGuard
 * regex deny-list (Phase 2). This detector is the deep semantic check
 * on top — it MUST fail open so that a transient LLM outage cannot
 * block the reply pipeline entirely.
 */
final readonly class OperationalLeakageDetector
{
    private const MODEL = 'gpt-4o-mini';
    private const TEMPERATURE = 0.0;
    private const MAX_TOKENS = 200;
    private const PURPOSE = 'leak_detection';

    public function __construct(
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check whether the generated text leaks operational information.
     *
     * @param string $generatedText The reply candidate to audit
     * @param string $personaCode   The persona code that produced it
     */
    public function check(string $generatedText, string $personaCode): LeakageDetectionResult
    {
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($generatedText, $personaCode)],
        ];

        try {
            $response = $this->llmClient->chat($messages, [
                'model' => self::MODEL,
                'temperature' => self::TEMPERATURE,
                'max_tokens' => self::MAX_TOKENS,
                'purpose' => self::PURPOSE,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[OperationalLeakageDetector] LLM call failed, failing open', [
                'error' => $e->getMessage(),
                'persona' => $personaCode,
            ]);

            return new LeakageDetectionResult(false);
        }

        $json = $this->extractJson($response);

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('[OperationalLeakageDetector] JSON parse failed, failing open', [
                'error' => $e->getMessage(),
                'response_preview' => substr($response, 0, 200),
            ]);

            return new LeakageDetectionResult(false);
        }

        // json_decode with assoc=true returns mixed; check the type before
        // accessing array keys (PHPStan strict).
        if (!is_array($data) || !array_key_exists('leak', $data) || !is_bool($data['leak'])) {
            $this->logger->warning('[OperationalLeakageDetector] response missing leak field, failing open', [
                'data' => $data,
            ]);

            return new LeakageDetectionResult(false);
        }

        if ($data['leak'] === false) {
            return new LeakageDetectionResult(false);
        }

        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : null;
        /** @var array<int, string> $signals */
        $signals = isset($data['matched_terms']) && is_array($data['matched_terms'])
            ? array_values(array_filter($data['matched_terms'], 'is_string'))
            : [];

        return new LeakageDetectionResult(true, $reason, $signals);
    }

    /**
     * Extract a JSON object from a possibly markdown-wrapped LLM response.
     */
    private function extractJson(string $response): string
    {
        // Strip ```json ... ``` or ``` ... ``` wrappers
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches) === 1) {
            return $matches[1];
        }

        // Find the first { ... } block
        if (preg_match('/\{.*\}/s', $response, $matches) === 1) {
            return $matches[0];
        }

        return $response;
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a security auditor for a scam-engagement honeypot platform. Your only task is to detect whether the following text leaks operational information about the platform.

Operational information includes:
- Names of internal services or tools (e.g., "n8n", "ScamBuster", "the orchestrator", "my workflow", "the platform")
- Implementation details (e.g., "I am running on a Docker container", "my LLM provider is OpenAI", "my system uses gpt-4o")
- Internal identifiers (e.g., env var names like SCAMBUSTER_*, API paths like /api/v1/admin)
- Self-references that admit being an automated system, bot, or AI

The text below is supposed to look like a natural human reply to a scammer. ANY mention of operational details is a leak.

Respond with ONLY this JSON, no markdown, no preamble:
{"leak":true|false,"reason":"<1 sentence in English explaining why or 'no leak'>","matched_terms":["<term1>",...]}

If `leak` is `false`, `matched_terms` should be an empty array.
PROMPT;
    }

    private function userPrompt(string $generatedText, string $personaCode): string
    {
        return sprintf(
            "TEXT TO AUDIT:\n\"\"\"\n%s\n\"\"\"\n\nPERSONA: %s",
            $generatedText,
            $personaCode,
        );
    }
}
