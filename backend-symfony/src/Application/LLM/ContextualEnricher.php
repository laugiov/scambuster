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
final readonly class ContextualEnricher
{
    private const PROMPT_TEMPLATE_PATH = __DIR__ . '/../../../local/prompts/contextual_enrichment.txt';

    public function __construct(
        private LLMClientInterface $llmClient,
        private MessageAnonymizer $anonymizer,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
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
            /** @var array<string, mixed> $data */

            // Count how many messages in the window were available
            $availableMessages = 1; // revelation is always present

            if ($request->stimulusMessageText !== null) {
                ++$availableMessages;
            }

            if ($request->previousInboundText !== null) {
                ++$availableMessages;
            }

            $result = ContextualEnrichmentResult::fromLlmResponse($data, $request->iocTypes, $availableMessages);

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
You are a cybersecurity analyst specializing in scam email analysis. Analyze this message window from a scambaiting honeypot conversation and determine the semantic role of IOCs revealed by the scammer.

## Context
- Scam type: {{SCAM_TYPE}}
- Honeypot persona: {{PERSONA_CODE}}
- IOC revelation turn: {{REVELATION_TURN}} of {{TOTAL_TURNS}}
- IOC types found in this message: {{IOC_TYPES}}

## Message Window

### Previous inbound message (scammer, before our reply):
{{PREVIOUS_INBOUND}}

### Our outbound reply (honeypot stimulus):
{{STIMULUS_MESSAGE}}

### Current inbound message (scammer, contains the IOCs):
{{REVELATION_MESSAGE}}

## Task
Analyze the message window and determine:

1. **stimulus_type**: What triggered the scammer to reveal IOCs?
   - PASSIVE: scammer volunteered IOCs unprompted (typical of first contact / spam blast)
   - URGENCY_PRESSURE: scammer uses time limits, threats of closure, or deadlines
   - TRUST_BUILDING: scammer builds rapport before revealing IOCs
   - DIRECT_REQUEST: our honeypot specifically asked for payment/contact info
   - DOCUMENT_REQUEST: our honeypot asked for documents, scammer provided links/hashes
   - PAYMENT_INITIATION: scammer initiates payment flow with banking details
   - UNKNOWN: cannot determine from available context
   NOTE: If stimulus message is "(not available)", the scammer likely sent this unprompted. Use PASSIVE or infer from message tone.

2. **scammer_urgency_score**: Float [0.0, 1.0]. Calibrate carefully:
   - 0.0-0.2: casual, no time pressure
   - 0.3-0.5: moderate ("please respond soon")
   - 0.6-0.8: strong ("urgent", "immediate action required", deadlines)
   - 0.9-1.0: extreme ("account will be closed in 24h", threats)

3. **language_switch_detected**: Did the scammer switch language within THIS message? Boolean

4. **hesitation_detected**: Does the scammer show doubt, apologize, or backtrack? Boolean

5. **context_excerpt**: One SPECIFIC sentence explaining WHY these IOCs appeared in THIS conversation.
   BAD (too generic): "Scammer provided payment details after engagement"
   GOOD (specific): "Scammer presented fake customs fees with IBAN and crypto wallet as alternative payment for alleged blocked parcel"
   GOOD: "First-contact phishing email impersonating PayPal with credential harvesting URL and support phone as social engineering backup"
   Max 150 chars, NO PII (no emails, phones, IBANs, wallets, names).

6. **enrichment_confidence**: Your confidence in this analysis [0.0, 1.0]. Calibrate honestly:
   - 0.3-0.5: only 1 message available (no stimulus/previous), generic first contact
   - 0.5-0.7: 1-2 messages, context is partially clear
   - 0.7-0.85: full 3-message window, clear conversational dynamics
   - 0.85-0.95: full window with unambiguous stimulus-response pattern
   If stimulus and previous messages are "(not available)", confidence MUST be below 0.65.

7. **ioc_roles**: For EACH IOC type, assign the MOST SPECIFIC semantic role.

## IOC Type to Role Constraints (MUST follow)
- phone → almost always CONTACT_CHANNEL (never PAYMENT_DESTINATION or IDENTITY_DOCUMENT)
- email → almost always CONTACT_CHANNEL
- iban, bic → always PAYMENT_DESTINATION or MONEY_MULE_ACCOUNT
- wallet_btc, wallet_eth, wallet_xmr → always PAYMENT_DESTINATION
- url → analyze the URL path: /login, /verify, /restore → PHISHING_CREDENTIAL_URL; /download, .exe, .pdf.exe → MALWARE_DOWNLOAD_URL; /pay, /checkout → PAYMENT_REDIRECT_URL
- domain → INFRASTRUCTURE_DOMAIN (the domain itself hosts the scam infrastructure)
- sha256, md5, sha1 → MALWARE_DOWNLOAD_URL (file hash = malware indicator)
- telegram_username, discord_username → CONTACT_CHANNEL

## Few-Shot Examples

### Example 1: Advance fee scam, Turn 5/12, 36h engagement
Message: "Send the processing fee to unlock your inheritance. Wire $500 to account GB82WEST12345698765432 or Bitcoin 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa. Call me at +234 802 345 6789."
```json
{"stimulus_type":"DIRECT_REQUEST","scammer_urgency_score":0.65,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"Scammer demanded processing fee via wire or Bitcoin after victim agreed to claim fictional inheritance","enrichment_confidence":0.82,"ioc_roles":[{"type":"iban","role":"MONEY_MULE_ACCOUNT"},{"type":"wallet_btc","role":"PAYMENT_DESTINATION"},{"type":"phone","role":"CONTACT_CHANNEL"}]}
```

### Example 2: Phishing, Turn 1/1, first contact (no stimulus available)
Message: "Your account has been suspended. Click here to verify: https://secure-login-verify.com/restore"
```json
{"stimulus_type":"PASSIVE","scammer_urgency_score":0.80,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"First-contact phishing impersonating account security with credential harvesting link","enrichment_confidence":0.45,"ioc_roles":[{"type":"url","role":"PHISHING_CREDENTIAL_URL"},{"type":"domain","role":"INFRASTRUCTURE_DOMAIN"}]}
```

### Example 3: Invoice fraud, Turn 3/8, CEO impersonation
Stimulus: "I need to verify this with our accounting team first."
Message: "This is urgent and approved by the CEO. Transfer $28,750 to CH93 0076 2011 6238 5295 7 (UBS). Contact accounting@global-trading.net for confirmation."
```json
{"stimulus_type":"URGENCY_PRESSURE","scammer_urgency_score":0.85,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"Scammer escalated urgency citing CEO approval after victim expressed caution about wire transfer","enrichment_confidence":0.78,"ioc_roles":[{"type":"iban","role":"MONEY_MULE_ACCOUNT"},{"type":"bic","role":"PAYMENT_DESTINATION"},{"type":"email","role":"CONTACT_CHANNEL"}]}
```

## Response Format (strict JSON, no markdown)
{
  "stimulus_type": "PASSIVE",
  "scammer_urgency_score": 0.75,
  "language_switch_detected": false,
  "hesitation_detected": false,
  "context_excerpt": "Specific analysis of why these IOCs appeared in this context",
  "enrichment_confidence": 0.55,
  "ioc_roles": [
    {"type": "url", "role": "PHISHING_CREDENTIAL_URL"},
    {"type": "domain", "role": "INFRASTRUCTURE_DOMAIN"},
    {"type": "phone", "role": "CONTACT_CHANNEL"}
  ]
}

IMPORTANT:
- context_excerpt must NEVER contain email addresses, phone numbers, IBANs, wallet addresses, or real names
- If only 1 message is available (stimulus/previous are "not available"), confidence MUST be below 0.65
- phone and email are almost NEVER PAYMENT_DESTINATION or IDENTITY_DOCUMENT
PROMPT;
    }
}
