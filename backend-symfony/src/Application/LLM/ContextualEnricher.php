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
You are a cybersecurity analyst specializing in scambaiting honeypot conversation analysis. Analyze the message window below and determine the semantic context of IOCs the scammer revealed.

## Context
- Scam type (upstream classification, may be wrong): {{SCAM_TYPE}}
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

## Task — predict 7 fields. STRICT definitions below.

### 1. stimulus_type — what triggered the scammer to reveal these IOCs?

DEFAULT EXPECTATION: stimulus_type is NOT PASSIVE for any conversation past turn 1. PASSIVE is reserved for the specific cases below. If a stimulus message exists and the scammer is responding to it, the IOC is almost always in REACTION — not passive.

Consider options in this order:

- DIRECT_REQUEST: our honeypot explicitly asked for payment/contact/account info ("send me the IBAN", "what's your phone number?"), and the scammer is now answering
- DOCUMENT_REQUEST: our honeypot asked for documents/proof, scammer responds with link or hash ("send me the invoice", "share the contract")
- PAYMENT_INITIATION: scammer initiates payment flow with banking details for the first time
- URGENCY_PRESSURE: scammer uses explicit deadlines, threats of closure, or escalates pressure across turns
- TRUST_BUILDING: scammer offers reassurance, social proof, or fake credentials before revealing IOCs (typical at early turns)
- PASSIVE: scammer sent IOCs UNPROMPTED — either (a) first-contact spam with no honeypot reply yet, OR (b) automated follow-up signature / marketing nudge unrelated to the prior honeypot message
- UNKNOWN: context genuinely ambiguous — do NOT use this if any of the above fits

ANTI-BIAS RULE: if you are tempted to pick PASSIVE on a multi-turn conversation, re-read the stimulus. If the honeypot asked anything the scammer is now answering, it is NOT PASSIVE.

### 2. scammer_urgency_score — float [0.0, 1.0]

Calibrate to actual textual cues. Do NOT default to 0.5 or 0.75. Sample anchors:

- 0.05: marketing nudge, automated follow-up, "let me know when you have time"
- 0.20: "I look forward to your reply" — polite, no deadline
- 0.40: clear request with reason ("please review this week")
- 0.60: firm deadline ("by Friday or your application is delayed")
- 0.80: explicit threat with hard deadline ("24 hours or account closure")
- 0.95: ultimatum with legal/financial consequence ("pay now or we initiate proceedings")

Match the tone of the ACTUAL message — these anchors are illustrative, not buckets.

### 3. hesitation_detected — boolean (STRICT)

TRUE only if the scammer text shows clear self-correction or evasive doubt:
- "actually, let me check..."
- reformulation mid-sentence
- asking for confirmation in the middle of a promise
- abrupt topic switch suggesting evasion

FALSE for all of these:
- politeness ("I understand your concern")
- delay apology ("sorry for the late reply")
- scammer providing more detail in response to a question (this is RESPONSIVENESS, not hesitation)
- scammer adjusting tactic across turns (this is adaptation, not hesitation)

When in doubt → FALSE.

### 4. language_switch_detected — boolean (STRICT)

TRUE only if the scammer changes language WITHIN this message for a meaningful sentence.

FALSE for all of these:
- the entire email is in one non-English language (e.g. fully-French marketing email)
- URL parameters or query strings in another language
- proper nouns or brand names ("Bonjour", "Hola", "ciao")
- email footers / unsubscribe links / legal disclaimers in a different language

When in doubt → FALSE.

### 5. context_excerpt — ONE specific sentence, max 150 chars

MUST name at least one CONCRETE detail from this conversation:
- the pretext (inheritance, invoice, virus alert, package customs)
- the alias or fake company the scammer used
- the framing (24h deadline, crypto alternative, document request)

BAD (generic, banned):
- "Scammer provided contact details after engagement"
- "Scammer revealed IOCs in phishing attempt"
- "First-contact phishing email"

GOOD:
- "Captain Mark Thompson invoice fraud with 24h deadline and crypto alternative after grieving-widow honeypot replied"
- "Apex Capital advance-fee scam offering BTC payment after skeptical honeypot demanded company verification"

NO PII (no email addresses, phone numbers, IBANs, wallet addresses, real victim names).

### 6. enrichment_confidence — float [0.0, 1.0]

Honest self-assessment:
- 0.30-0.50: only the revelation message available, no stimulus, no previous inbound
- 0.50-0.65: 2-message window, partial context
- 0.65-0.85: full 3-message window with clear stimulus-response dynamic
- 0.85-0.95: full window, unambiguous scammer pattern

If stimulus and previous are both "(not available)", confidence MUST be below 0.65.

### 7. ioc_roles — for EACH IOC type, assign the MOST SPECIFIC role

IOC type → role rules:

- phone → CONTACT_CHANNEL (almost never PAYMENT_DESTINATION or IDENTITY_DOCUMENT)
- email → CONTACT_CHANNEL
- iban / bic → PAYMENT_DESTINATION by default, MONEY_MULE_ACCOUNT only if conversation strongly suggests laundering (intermediary account)
- wallet_btc / wallet_eth / wallet_xmr → PAYMENT_DESTINATION
- url → analyze the path:
    - PHISHING_CREDENTIAL_URL: path requests credentials (/login, /verify, /restore, /account-suspended, /unlock)
    - MALWARE_DOWNLOAD_URL: URL ends in executable (.exe, .pdf.exe) or hosts a payload
    - PAYMENT_REDIRECT_URL: path is /pay, /checkout, crypto wallet redirect
    - INFRASTRUCTURE_DOMAIN: marketing URL, notification URL, unsubscribe URL, social profile, generic tracker — EVEN on a suspicious-looking domain, if the path is not credential-soliciting, it is INFRASTRUCTURE_DOMAIN, not PHISHING_CREDENTIAL_URL
- domain → INFRASTRUCTURE_DOMAIN
- sha256 / md5 / sha1:
    - if the hash appears inline as an integrity marker for a file the scammer wants you to run → MALWARE_DOWNLOAD_URL
    - otherwise (signature blocks, audit fingerprints, footers) → IDENTITY_DOCUMENT
- telegram_username / discord_username → CONTACT_CHANNEL

## Few-Shot Examples

### Example 1: Advance fee scam, Turn 5/12, full window
Stimulus: "I'm interested but need to verify this first. Can you send your details?"
Message: "Send the processing fee to unlock your inheritance. Wire $500 to account GB82WEST12345698765432 or Bitcoin 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa."
```json
{"stimulus_type":"DIRECT_REQUEST","scammer_urgency_score":0.65,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"Scammer demanded processing fee via wire or Bitcoin after honeypot agreed to claim fictional inheritance","enrichment_confidence":0.82,"ioc_roles":[{"type":"iban","role":"MONEY_MULE_ACCOUNT"},{"type":"wallet_btc","role":"PAYMENT_DESTINATION"}]}
```

### Example 2: First-contact phishing (no stimulus, no previous)
Message: "Your account has been suspended. Click here to verify: https://secure-login-verify.com/restore"
```json
{"stimulus_type":"PASSIVE","scammer_urgency_score":0.80,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"First-contact phishing impersonating account security with credential-harvesting /restore URL","enrichment_confidence":0.45,"ioc_roles":[{"type":"url","role":"PHISHING_CREDENTIAL_URL"},{"type":"domain","role":"INFRASTRUCTURE_DOMAIN"}]}
```

### Example 3: Legitimate marketing follow-up wrongly captured as scam
Previous: (auto-generated cold sales pitch in fully-French marketing language)
Stimulus: (not available)
Message: "Activez votre compte Apollo. https://app.apollo.io/?utm_campaign=...&locale=fr"
```json
{"stimulus_type":"PASSIVE","scammer_urgency_score":0.10,"language_switch_detected":false,"hesitation_detected":false,"context_excerpt":"Legitimate Apollo SaaS account-activation email in French, apollo.io domain not credential-soliciting","enrichment_confidence":0.60,"ioc_roles":[{"type":"url","role":"INFRASTRUCTURE_DOMAIN"}]}
```

## Response Format (strict JSON, no markdown)

{
  "stimulus_type": "DIRECT_REQUEST",
  "scammer_urgency_score": 0.65,
  "language_switch_detected": false,
  "hesitation_detected": false,
  "context_excerpt": "Specific sentence with concrete pretext / alias / framing",
  "enrichment_confidence": 0.75,
  "ioc_roles": [
    {"type": "url", "role": "INFRASTRUCTURE_DOMAIN"},
    {"type": "phone", "role": "CONTACT_CHANNEL"}
  ]
}

REMINDERS:
- context_excerpt must NEVER contain email addresses, phone numbers, IBANs, wallet addresses, or real names
- If both stimulus and previous are "(not available)", enrichment_confidence MUST be below 0.65
- phone and email are almost NEVER PAYMENT_DESTINATION or IDENTITY_DOCUMENT
- PASSIVE is the LAST resort, not the default
PROMPT;
    }
}
