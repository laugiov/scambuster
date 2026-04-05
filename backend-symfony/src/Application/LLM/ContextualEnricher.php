<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * LLM agent that enriches IOC context with semantic analysis.
 *
 * Analyzes a 3-message window (previous inbound, stimulus, revelation)
 * to determine the semantic role of IOCs, scammer urgency, and other
 * contextual signals. Fail-safe: returns null on any failure.
 */
final class ContextualEnricher
{
    private const PROMPT_TEMPLATE_PATH = __DIR__ . '/../../../local/prompts/contextual_enrichment.txt';

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly MessageAnonymizer $anonymizer,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Enrich IOC context using LLM analysis.
     *
     * Returns null on any failure (LLM timeout, invalid JSON, etc.).
     * NEVER throws.
     */
    public function enrich(ContextualEnrichmentRequest $request): ?ContextualEnrichmentResult
    {
        try {
            $prompt = $this->buildPrompt($request);

            $messages = [
                ['role' => 'system', 'content' => 'You are a cybersecurity analyst. Respond with valid JSON only, no markdown.'],
                ['role' => 'user', 'content' => $prompt],
            ];

            $options = [
                'temperature' => 0.3,
                'max_tokens' => 500,
                'purpose' => 'contextual_enrichment',
            ];

            $this->logger->debug('[ContextualEnricher] Calling LLM', [
                'ioc_types' => $request->iocTypes,
                'scam_type' => $request->scamType,
                'persona' => $request->personaCode,
            ]);

            $response = $this->llmClient->chat($messages, $options);

            $jsonText = $this->extractJson($response);
            $data = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                $this->logger->warning('[ContextualEnricher] LLM response is not a JSON object', [
                    'response' => substr($response, 0, 200),
                ]);

                return null;
            }

            $result = ContextualEnrichmentResult::fromLlmResponse($data, $request->iocTypes);

            // PII post-validation on context_excerpt
            $result = $this->validateExcerptPii($result);

            // Dispatch usage event
            $promptTokens = (int) ceil(\strlen($prompt) / 4);
            $completionTokens = (int) ceil(\strlen($response) / 4);

            $this->dispatcher->dispatch(new LlmCallCompletedEvent(
                provider: 'openai',
                model: 'gpt-4o-mini',
                purpose: 'contextual_enrichment',
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ));

            $this->logger->info('[ContextualEnricher] Enrichment completed', [
                'stimulus_type' => $result->stimulusType,
                'urgency_score' => $result->urgencyScore,
                'confidence' => $result->enrichmentConfidence,
                'ioc_roles_count' => \count($result->iocRoles),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->warning('[ContextualEnricher] Enrichment failed', [
                'error' => $e->getMessage(),
                'ioc_types' => $request->iocTypes ?? [],
            ]);

            return null;
        }
    }

    /**
     * Build the enrichment prompt from template with anonymized message texts.
     */
    private function buildPrompt(ContextualEnrichmentRequest $request): string
    {
        $template = @file_get_contents(self::PROMPT_TEMPLATE_PATH);

        if ($template === false) {
            // Fallback inline prompt if template file is missing
            $template = $this->fallbackPromptTemplate();
        }

        $replacements = [
            '{{SCAM_TYPE}}' => $request->scamType,
            '{{PERSONA_CODE}}' => $request->personaCode,
            '{{REVELATION_TURN}}' => (string) $request->revelationTurn,
            '{{TOTAL_TURNS}}' => (string) $request->totalTurns,
            '{{IOC_TYPES}}' => implode(', ', $request->iocTypes),
            '{{PREVIOUS_INBOUND}}' => $request->previousInboundText !== null
                ? $this->anonymizer->anonymize($request->previousInboundText)
                : '(not available)',
            '{{STIMULUS_MESSAGE}}' => $request->stimulusMessageText !== null
                ? $this->anonymizer->anonymize($request->stimulusMessageText)
                : '(not available)',
            '{{REVELATION_MESSAGE}}' => $this->anonymizer->anonymize($request->revelationMessageText),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Extract JSON from LLM response (handles markdown code blocks).
     */
    private function extractJson(string $response): string
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }

    /**
     * Validate that context_excerpt does not contain PII.
     * If PII is detected, replace excerpt with a safe placeholder.
     */
    private function validateExcerptPii(ContextualEnrichmentResult $result): ContextualEnrichmentResult
    {
        if ($this->anonymizer->containsPii($result->contextExcerpt)) {
            $this->logger->warning('[ContextualEnricher] PII detected in context_excerpt, redacting');

            return new ContextualEnrichmentResult(
                stimulusType: $result->stimulusType,
                urgencyScore: $result->urgencyScore,
                languageSwitch: $result->languageSwitch,
                hesitationDetected: $result->hesitationDetected,
                contextExcerpt: '[PII detected - redacted]',
                enrichmentConfidence: $result->enrichmentConfidence,
                iocRoles: $result->iocRoles,
            );
        }

        return $result;
    }

    private function fallbackPromptTemplate(): string
    {
        return <<<'PROMPT'
Analyze this 3-message window from a scambaiting honeypot conversation.

Context: Scam type={{SCAM_TYPE}}, Persona={{PERSONA_CODE}}, Turn={{REVELATION_TURN}}/{{TOTAL_TURNS}}, IOC types={{IOC_TYPES}}

Previous inbound: {{PREVIOUS_INBOUND}}
Our stimulus: {{STIMULUS_MESSAGE}}
Revelation message: {{REVELATION_MESSAGE}}

Return JSON with: stimulus_type, scammer_urgency_score, language_switch_detected, hesitation_detected, context_excerpt, enrichment_confidence, ioc_roles (array of {type, role}).
PROMPT;
    }
}
