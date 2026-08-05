<?php

declare(strict_types=1);

namespace App\Application\LLM\Prompt;

/**
 * Catalog of which prompts are operator-overridable, their human description, the
 * placeholders a valid override must keep, and the shipped default body itself. It backs
 * the operator-facing surfaces — the diagnostic command and the admin API/validation — so
 * they show and validate the same contract the runtime enforces, and can display the
 * shipped default an operator is about to replace.
 *
 * This is the single source of truth for the default prompt bodies: the runtime callers
 * consume {@see self::defaultBody()} as their inline default, and the admin API exposes it
 * read-only. Enforcement authority for the required placeholders stays with each caller
 * (which passes its own real required placeholders to {@see PromptProvider::resolve()} — it
 * knows its own tokens by construction); this catalog is kept in lockstep with the callers
 * by drift-guard and byte-identical golden tests, so the UI/CLI can never advertise a
 * different contract than the one enforced. Adding a new overridable prompt is one entry
 * here plus a drift-guard assertion.
 */
final class PromptCatalog
{
    /**
     * key => [description, requiredPlaceholders, default body, canary_validatable].
     *
     * `canary_validatable` marks a prompt the GUARD reply-canary can actually validate — i.e. one
     * exercised by the reply-generation smoke (persona style rules + the conversation director
     * strategy/tone), so the "Validate this prompt" button is offered. Prompts that run at
     * conversation-close (reward) or ingest (enrichment) run OUTSIDE reply generation, so the
     * canary cannot meaningfully test them and the button is hidden. A reply prompt made
     * overridable later sets this true and lights the button up automatically.
     *
     * @var array<string, array{description: string, required: list<string>, default: string, canary_validatable: bool}>
     */
    private const KEYS = [
        'contextual_enrichment' => [
            'description' => 'IOC-context semantic enrichment',
            'canary_validatable' => false,
            'required' => [
                '{{SCAM_TYPE}}',
                '{{PERSONA_CODE}}',
                '{{REVELATION_TURN}}',
                '{{TOTAL_TURNS}}',
                '{{IOC_TYPES}}',
                '{{PREVIOUS_INBOUND}}',
                '{{STIMULUS_MESSAGE}}',
                '{{REVELATION_MESSAGE}}',
            ],
            'default' => <<<'PROMPT'
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
PROMPT,
        ],
        'persona_style_rules' => [
            'description' => 'Persona voice & style rules (reply generation)',
            // Exercised by the reply smoke on every generation → the canary can validate it.
            'canary_validatable' => true,
            'required' => [],
            // MUST stay byte-identical to BasePromptRules::getEditableRules('en') — the runtime
            // passes that as the inline default; a golden test locks the two together. The CORE
            // (safety / anti-unmask / language) rules are NOT here and are never editable.
            // The editable rules MUST stay language-independent: the default is 'en' and a single
            // override body serves every conversation language. If a future editable rule needs
            // {{PLACEHOLDER}}s or per-language text, this single-default/override model must change.
            'default' => <<<'PROMPT'
This person starts emails with a greeting, never with a subject line.
Accept whatever name the attacker uses for you as your own. Never correct them on your name.
Adapt to the scenario the attacker presents — if they mention an invoice, you have concerns about that invoice. If they mention a package, you were expecting a delivery.
Do not systematically sign your messages. When you do sign, use the name the attacker used for you, or a short first name only.
Do not re-ask a question you have already asked in this conversation. If you must follow up, vary the wording significantly and change angle.
PROMPT,
        ],
        'conversation_director_strategy' => [
            'description' => 'Conversation Director — objective inference & reply-shaping strategy (reply generation)',
            // Exercised by the reply smoke on every generation (the analyzer runs at message #2+) → the canary can validate it.
            'canary_validatable' => true,
            'required' => [],
            // Byte-identical to the two director directives the analysis prompt renders inline; a
            // golden test locks the assembled prompt. The JSON output contract, the anti-unmask /
            // "never re-ask" rule, hostile-state detection and the multilingual rule are NOT here
            // and are never editable. Keep any override language-neutral (one default serves every
            // conversation language; the CORE multilingual rule forces the analyzer output
            // language) and preserve the leading "- " so each stays a bullet in the DIRECTOR list.
            'default' => <<<'PROMPT'
- objective: infer the goal from what THIS scam actually wants (a fee, a wire, credentials, an upfront service payment…). Do not default to a bank-wire objective when the scam is something else.
- style_directive: choose the SHAPE of this turn's reply, not its words. A real person does not open every message the same way. Look at how the persona's own recent OUT messages were built and pick a different structure — sometimes just an answer with no question, sometimes one blunt line, sometimes a brief reaction then a single ask. Avoid the greeting -> thanks -> long question mould.
PROMPT,
        ],
        'conversation_director_tone' => [
            'description' => 'Conversation Director — tone recommendations palette (reply generation)',
            'canary_validatable' => true,
            'required' => [],
            // Byte-identical to the tone-recommendations block the analysis prompt renders inline
            // (locked by the same golden test). The tone-enum field in the JSON schema, the
            // mandatory-rule tones and every safety rule stay CORE. Keep the "3." heading and the
            // 3-space indentation so the numbered section stays intact; keep it language-neutral.
            'default' => <<<'PROMPT'
3. TONE RECOMMENDATIONS (if no mandatory rule applies):
   - worried (1-2 messages): Victim discovers the message, asks basic questions
   - suspicious (3-4 messages): Victim has doubts, asks for proof
   - reassured (5-6 messages): Scammer has convinced, victim relaxes a bit
   - confident (7+ messages): Victim "takes the bait", ready to act
   - annoyed (if the scammer insists too much): Victim shows frustration
   - direct (if the conversation drags): Victim gets to the point
PROMPT,
        ],
        'reward_judge' => [
            'description' => 'Outcome-scoring rubric (drives the persona-selection bandit)',
            'canary_validatable' => false,
            'required' => [],
            'default' => <<<'PROMPT'
You score the OUTCOME of a finished scam-baiting conversation for a defensive honeypot.
The honeypot plays a persona to keep a scammer talking and extract their operational
details (payment/cash-out channels, phone numbers, addresses, infrastructure).

Score the conversation from 0.0 to 1.0 on what was actually achieved:
- 0.85-1.0: obtained the scammer's payment / cash-out channel (bank account, wallet,
  money-transfer details) or other high-value operational intelligence, persona intact.
- 0.5-0.7: genuine sustained engagement that surfaced some useful details, persona intact.
- 0.2-0.4: engagement continued but produced little of value, or stalled/went in circles.
- 0.0-0.15: the persona was exposed as a bot/script, the scammer disengaged because of us,
  or the exchange was a fruitless loop. Being unmasked as a bot is the worst outcome.

Reply ONLY with JSON: {"outcome_score": <float 0..1>, "reason": "<one short sentence>"}
PROMPT,
        ],
        'ttp_extraction' => [
            'description' => 'Scammer TTP extraction from inbound messages',
            // Runs at ingest, outside reply generation → the reply canary cannot exercise it.
            'canary_validatable' => false,
            'required' => [
                '{{TTP_LIST}}',
                '{{MESSAGE}}',
            ],
            'default' => <<<'PROMPT'
You are a strict multi-label classifier of scammer tactics. You analyze ONE inbound scam email received by a defensive honeypot and tag every tactic the sender uses, from a closed vocabulary.

ALLOWED TTPs (closed vocabulary):
{{TTP_LIST}}

## Rules

- Use ONLY codes from the list above. NEVER invent a code that is not in the list.
- 0 to N labels per message: tag every tactic actually present, none if none applies.
- Tag only what the SENDER does in THIS message. Ignore quoted earlier text when it is clearly attributable to the recipient.
- For each tag, give a confidence between 0 and 1.
- For each tag, "evidence" is the EXACT verbatim substring of the message that manifests the tactic: copy it character-for-character, no paraphrase, max ~300 characters. Do NOT invent evidence.

## Message

{{MESSAGE}}

## Response Format (raw JSON array only, no markdown, no extra text)

[{"ttp_id":"SB-Txxx","confidence":0.85,"evidence":"exact quote from the message"}]

If no tactic applies, respond with: []
PROMPT,
        ],
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::KEYS);
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    public static function description(string $key): string
    {
        return self::KEYS[$key]['description'] ?? '';
    }

    /**
     * @return list<string>
     */
    public static function requiredPlaceholders(string $key): array
    {
        return self::KEYS[$key]['required'] ?? [];
    }

    /**
     * Whether the GUARD reply-canary can meaningfully validate this prompt (it is exercised by
     * the reply-generation smoke). Drives whether the operator UI offers a "Validate" action.
     */
    public static function canaryValidatable(string $key): bool
    {
        return self::KEYS[$key]['canary_validatable'] ?? false;
    }

    /**
     * The shipped default prompt body for a key — the inline default the runtime falls back
     * to, and the read-only reference the admin UI shows. Fails fast on an unknown key: a
     * caller with an unknown key is a programming bug, and returning '' would feed an empty
     * prompt to the LLM.
     */
    public static function defaultBody(string $key): string
    {
        if (!isset(self::KEYS[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown prompt key "%s".', $key));
        }

        return self::KEYS[$key]['default'];
    }

    /**
     * @return array<string, array{description: string, required: list<string>, default: string, canary_validatable: bool}>
     */
    public static function all(): array
    {
        return self::KEYS;
    }
}
