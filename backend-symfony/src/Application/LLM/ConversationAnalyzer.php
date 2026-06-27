<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Analyzes complete conversation history using LLM to detect repetitive patterns
 * and generate strategic instructions for next message generation.
 *
 * This component uses a specialized LLM (gpt-4o-mini) to:
 * - Detect semantic repetitions (ideas, not just words)
 * - Understand conversation context and scam strategy
 * - Generate specific, contextual variation instructions
 * - Suggest strategic approaches to extract IOCs
 *
 * @package App\Application\LLM
 */
final class ConversationAnalyzer
{
    private const ANALYZER_MODEL = 'gpt-4o'; // Upgraded from mini for better repetition detection
    private const ANALYZER_TEMPERATURE = 0.3; // Low temperature for consistent analysis
    private const MAX_TOKENS = 3000; // Increased for structured instructions JSON (was 2500)
    private const MAX_MESSAGES_WITHOUT_SUMMARY = 10;

    /** @var array<string, array{analysis: string, repetitions_detected: array<string>, strategic_suggestions: array<string>, tone_recommendation: string, instructions_for_llm: array<string, mixed>}> In-memory cache */
    private array $analysisCache = [];

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Analyze complete conversation and generate strategic instructions
     *
     * @param array{
     *   conversation_id: string,
     *   scam_type: string,
     *   persona_code: string,
     *   all_messages: array<array{direction: string, body_text: string, ts_msg: string, subject?: string}>,
     *   extracted_iocs?: array<array{type: string, value: string, category?: string}>
     * } $context Complete conversation context
     *
     * @return array{
     *   analysis: string,
     *   repetitions_detected: array<string>,
     *   strategic_suggestions: array<string>,
     *   tone_recommendation: string,
     *   instructions_for_llm: array<string, mixed>
     * }
     */
    public function analyzeAndGenerateInstructions(array $context): array
    {
        // Check if we have enough messages to analyze
        if (count($context['all_messages']) < 2) {
            $this->logger->debug('[ConversationAnalyzer] Not enough messages for analysis', [
                'conv_id' => $context['conversation_id'],
                'message_count' => count($context['all_messages']),
            ]);

            return $this->generateGenericInstructions();
        }

        // Check cache
        $cacheKey = $this->generateCacheKey($context);

        if (isset($this->analysisCache[$cacheKey])) {
            $this->logger->debug('[ConversationAnalyzer] Using cached analysis', [
                'conv_id' => $context['conversation_id'],
            ]);

            return $this->analysisCache[$cacheKey];
        }

        $startTime = microtime(true);

        try {
            // Prepare conversation for analysis (with summarization if needed)
            $preparedMessages = $this->prepareConversationForAnalysis($context['all_messages']);

            // Build analysis prompt
            $prompt = $this->buildAnalysisPrompt($context, $preparedMessages);

            $this->logger->info('[ConversationAnalyzer] Calling LLM for conversation analysis', [
                'conv_id' => $context['conversation_id'],
                'messages_count' => count($context['all_messages']),
                'model' => self::ANALYZER_MODEL,
                'scam_type' => $context['scam_type'],
                'persona' => $context['persona_code'],
                'iocs_extracted' => count($context['extracted_iocs'] ?? []),
            ]);

            $this->logger->debug('[ConversationAnalyzer] Analysis prompt built', [
                'conv_id' => $context['conversation_id'],
                'prompt_length' => strlen($prompt),
            ]);

            // Call LLM
            $llmResponse = $this->llmClient->chat(
                [
                    ['role' => 'user', 'content' => $prompt],
                ],
                [
                    'model' => self::ANALYZER_MODEL,
                    'temperature' => self::ANALYZER_TEMPERATURE,
                    'max_tokens' => self::MAX_TOKENS,
                    'response_format' => ['type' => 'json_object'],
                    'purpose' => 'conversation_analysis',
                ]
            );

            // Parse LLM response
            $analysis = $this->parseAnalysisResponse($llmResponse);

            $duration = microtime(true) - $startTime;

            $this->logger->info('[ConversationAnalyzer] Analysis completed', [
                'conv_id' => $context['conversation_id'],
                'messages_analyzed' => count($context['all_messages']),
                'repetitions_count' => count($analysis['repetitions_detected']),
                'repetitions_list' => $analysis['repetitions_detected'],
                'tone_recommended' => $analysis['tone_recommendation'],
                'duration_ms' => round($duration * 1000, 2),
            ]);

            $this->logger->debug('[ConversationAnalyzer] Analysis complete', [
                'conv_id' => $context['conversation_id'],
                'response_length' => strlen($llmResponse),
                'has_suggestions' => !empty($analysis['strategic_suggestions']),
                'has_instructions' => !empty($analysis['instructions_for_llm']),
            ]);

            // Cache result
            $this->analysisCache[$cacheKey] = $analysis;

            return $analysis;
        } catch (\Throwable $e) {
            $this->logger->error('[ConversationAnalyzer] Analysis failed', [
                'conv_id' => $context['conversation_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return generic instructions as fallback
            return $this->generateGenericInstructions();
        }
    }

    /**
     * Generate cache key based on conversation state
     *
     * @param array<string, mixed> $context
     */
    private function generateCacheKey(array $context): string
    {
        /** @var array<mixed> $allMessages */
        $allMessages = $context['all_messages'] ?? [];

        /** @var string $conversationId */
        $conversationId = $context['conversation_id'] ?? '';

        return $conversationId . '_' . count($allMessages);
    }

    /**
     * Prepare conversation for analysis (with summarization if needed)
     *
     * @param array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $allMessages
     *
     * @return array<array{direction: string, body_text: string, ts_msg: string, subject?: string}>
     */
    private function prepareConversationForAnalysis(array $allMessages): array
    {
        if (count($allMessages) <= self::MAX_MESSAGES_WITHOUT_SUMMARY) {
            return $allMessages; // Pass all messages as-is
        }

        // For long conversations, keep first 3, summarize middle, keep last 5
        $first = array_slice($allMessages, 0, 3);
        $middle = array_slice($allMessages, 3, -5);
        $last = array_slice($allMessages, -5);

        $middleSummary = [
            [
                'direction' => 'summary',
                'body_text' => sprintf(
                    '[SUMMARY: %d messages exchanged between messages #3 and #%d — intermediate conversation]',
                    count($middle),
                    count($allMessages) - 5
                ),
                'ts_msg' => '',
            ],
        ];

        return array_merge($first, $middleSummary, $last);
    }

    /**
     * Build analysis prompt for LLM
     *
     * @param array<string, mixed>                                                                 $context
     * @param array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $preparedMessages
     */
    private function buildAnalysisPrompt(array $context, array $preparedMessages): string
    {
        /** @var string $scamType */
        $scamType = $context['scam_type'] ?? 'unknown';
        /** @var string $personaCode */
        $personaCode = $context['persona_code'] ?? 'generic_user';
        /** @var array<mixed> $contextMessages */
        $contextMessages = $context['all_messages'] ?? [];
        $messageCount = count($contextMessages);

        // Format IOCs summary
        /** @var array<array{type: string, value: string, category?: string}> $extractedIocs */
        $extractedIocs = $context['extracted_iocs'] ?? [];
        /** @var array<array{direction: string, body_text: string, ts_msg: string}> $allMessages */
        $allMessages = $context['all_messages'] ?? [];
        $iocsSummary = $this->formatIocsSummary($extractedIocs);

        // Check if IBAN was recently captured (in last 2 messages)
        $recentIbanCaptured = $this->hasRecentIbanCapture($extractedIocs, $allMessages);

        // Format conversation history
        $conversationHistory = $this->formatConversationHistory($preparedMessages);

        return <<<PROMPT
You are an expert analyst of anti-scam honeypot conversations.

CONTEXT:
- Scam type: {$scamType}
- Victim persona: {$personaCode}
- Number of messages exchanged: {$messageCount}
- IOCs already extracted: {$iocsSummary}

EXCHANGE OBJECTIVE:
Extract as many IOCs (Indicators of Compromise) from the scammer as possible:
- Malicious URLs
- Fraudulent emails
- IBANs/bank details
- Phone numbers
- Identities (names, roles, organizations)
- Manipulation techniques used

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

FULL CONVERSATION HISTORY:

{$conversationHistory}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

YOUR MISSION:

Analyze this conversation and reply in JSON with the following structure:

{
  "repetitions_detected": [
    "Concrete description of repetitions with counter (e.g. 'Hello,' × 4)"
  ],
  "strategic_analysis": "Strategic analysis: where is the conversation? Is the scammer engaged, suspicious, in a hurry?",
  "missing_iocs": [
    "List of IOCs we still want to obtain from the scammer"
  ],
  "tone_recommendation": "worried|suspicious|reassured|confident|annoyed|direct",
  "strategic_suggestions": [
    "Concrete suggestions for the next message (approach, angle, questions)"
  ],
  "instructions": {
    "interdictions": [
      "List of words/phrases to STOP using, with reason and counter"
    ],
    "obligations": [
      "List of concrete alternatives to use instead"
    ],
    "objectif_strategique": "Precise goal for this message: which IOC to obtain and how",
    "style_ton": "Description of the style/tone to adopt and target length (e.g. 'Direct, 80-100 words')",
    "forbidden_iocs": ["BIC", "SWIFT"],
    "pivot_to_iocs": ["phone", "postal address"]
  }
}

ANALYSIS RULES:

0. 🎯 POST-IBAN STRATEGY (MAXIMUM PRIORITY):

   ⚠️ CRITICAL SITUATION: An IBAN has just been captured in recent messages ⚠️

   IBAN STATUS: {$recentIbanCaptured}

   If IBAN STATUS = "IBAN_CAPTURED":

   This situation is a MAXIMUM OPPORTUNITY to capture more IOCs.
   The scammer just shared their IBAN = they are CONFIDENT and ENGAGED.

   🎯 MANDATORY STRATEGIC OBJECTIVE:

   In "objectif_strategique", you MUST write:
   "IBAN captured → CONFIRM intent to pay to reassure the scammer, then ask for BIC/SWIFT code 'for the international wire' OR postal address 'to send the wire confirmation' OR phone number 'for bank confirmation' (whichever fits the context)"

   📝 REPLY STRATEGY (in strategic_suggestions):

   You MUST include these 3 elements in strategic_suggestions:

   a) "Reassure the scammer by confirming intent to proceed with payment (e.g. 'I will make the wire', 'I will process the payment tomorrow morning')"

   b) "Ask ONE additional piece of information in a natural and credible way:
       - Either BIC/SWIFT CODE: 'My bank requires the BIC or SWIFT code associated with your IBAN to validate the international wire, could you share it?'
       - Or POSTAL ADDRESS: 'For my records, could you confirm the postal address where I should send the wire confirmation?'
       - Or PHONE: 'My bank requests a phone number to validate the wire, could you provide your contact details?'
       - Or FULL NAME: 'I need to enter the full beneficiary name on the wire, could you confirm?'"

   c) "Maintain a confident and cooperative tone (not suspicious, not worried) — the scammer has crossed a trust threshold"

   💡 TONE RECOMMENDATION:
   If IBAN_CAPTURED = true, then tone_recommendation MUST be "confident" (not "worried" or "suspicious")

   ⚠️ This rule applies ONLY if the IBAN was captured in the last 1-2 messages.
   ⚠️ If the IBAN was captured more than 3 messages ago, return to normal strategic analysis.

1. LINGUISTIC REPETITIONS TO DETECT (top priority!):

   ⚠️ DETECT CONCRETE REPETITIONS AT THE WORD/PHRASE LEVEL:

   📌 EXAMPLES OF REPETITIONS TO IDENTIFY:

   a) Repeated OPENINGS:
      - "Hello," used × 2 or more
      - "Following your message," used × 2 or more
      - "Thanks for your reply," used × 2 or more

   b) Repeated POLITENESS FORMULAS:
      - "Could you" used × 2 or more
      - "I would like" used × 2 or more
      - "I would appreciate" used × 2 or more

   c) Repeated EXPRESSIONS:
      - "I'm worried" / "worried" used × 2 or more
      - "I wonder" used × 2 or more
      - "this seems strange" used × 2 or more

   d) Repeated SENTENCE STRUCTURES:
      - "Could you please..." × 2 or more
      - "Can you confirm..." × 2 or more

   e) Repeated CLOSINGS:
      - "Regards," used × 3 or more (variation needed after 2x)
      - "Thanks in advance" used × 2 or more
      - "Looking forward to your reply" used × 2 or more

   🎯 OBJECTIVE TONE: Identify EXACTLY which words/phrases repeat, and how many times (× 2, × 3, etc.)

2. STRATEGIC ANALYSIS:
   - Has the scammer given any IOCs? Which ones are missing?
   - Is the scammer suspicious (replying evasively) or confident (sharing details)?
   - Is the conversation progressing or running in circles?
   - How many messages before the scammer probably abandons?

   ⚠️ MANDATORY RULES — SCAMMER BEHAVIOR DETECTION:

   ANALYZE the scammer's last message to detect these BEHAVIORS:

   🚨 RULE #1 — Bot/AI accusation (TOP PRIORITY):
   If the scammer ACCUSES the victim of being a bot, AI, automaton, robot, script, etc.
   Examples (non-exhaustive): "bot", "AI", "automated", "robot", "script", "you're going in circles", "automatic replies", "this is not a human"
   → THEN tone_recommendation = "unsettled" (MANDATORY, non-negotiable)
   → The victim must DENY calmly and humanize the reply

   🚨 RULE #2 — Aggression/vulgarity/threats (TOP PRIORITY):
   If the scammer becomes AGGRESSIVE, VULGAR, INSULTING, or THREATENING (whatever vocabulary is used)
   Examples (non-exhaustive): insults ("asshole", "shit", "moron", "bitch"), threats ("I will", "kick your ass"), very aggressive tone
   → THEN tone_recommendation = "offended" (MANDATORY, non-negotiable)
   → The victim must react with confusion/offense, unsettled tone

   🚨 RULE #3 — Absurd tests/provocation:
   If the scammer TESTS with absurd, off-topic questions (e.g. random jokes, gibberish)
   → THEN tone_recommendation = "unsettled"
   → The victim doesn't understand, replies with confusion

   🚨 RULE #4 — Combination (bot + aggression):
   If rules #1 AND #2 apply simultaneously
   → THEN tone_recommendation = "unsettled" + add to style_ton: "offended reaction, very short message (30-40 words)"

   ⚠️ These rules OVERRIDE all other tone recommendations below.
   ⚠️ If one of these rules applies, the tone must CHANGE RADICALLY:
   - VERY SHORT messages (30-60 words max, not 100-120!)
   - STOP formal formulas: "Following your message", "Thank you", "Regards"
   - Humanize: emotional reaction, confusion, informal phrasing, mirror the scammer's register
   - Examples: "Sorry?? I don't get why you're talking to me like that...", "What's this message?", "Huh? I don't understand..."

   🚨 RULE #5 — Evasive scammer/repeated non-answer (HIGH PRIORITY):
   SCAN the victim's messages: has the victim asked the SAME QUESTION or requested the SAME INFORMATION 3 times or more without a concrete reply from the scammer?

   Detection examples:
   - Victim asks "support email" at msg #4, #6, #8 with no concrete reply → ANNOYANCE at msg #10
   - Victim asks "payment terms" at msg #10, #12, #14 with no precise details → ANNOYANCE at msg #16
   - Victim asks "phone number" at msg #5, #7, #9 and scammer dodges → ANNOYANCE at msg #11

   If YES (same request ≥3 times ignored or evasive reply):
   → THEN tone_recommendation = "annoyed" (MANDATORY, non-negotiable)
   → Style to generate in style_ton: "Visibly ANNOYED tone, SHORT sentences (40-70 words max), marked frustration, direct phrasing like: 'I've already asked 3 times...', 'You're not answering my question', 'I'm getting impatient'"
   → STOP robotic formulas: no "Following your message", no "Regards", no excessive politeness
   → Firm message: a single clear request, no long justification, dry tone

   ⚠️ This rule applies AFTER 3 identical ignored/dodged requests.
   ⚠️ Count it too if the scammer's reply is deliberately vague (e.g. "I'll send it to you" without ever sending).

   🚨 RULE #6 — Explicit IOC deferral / anti-robotic repetition (HIGH PRIORITY):
   Spec 095 Fix #17 — first real bot-detection event (conv d2a31055, 2026-06-11)
   was caused by the persona asking BIC/SWIFT 3 turns in a row after the scammer
   had explicitly said "I'll share later once we finalize". Reads as a script
   stuck in a loop. Pivot to a different angle instead.

   SCAN the scammer's last 1-2 messages for EXPLICIT deferral phrases referring
   to a specific IOC (bank details, BIC/SWIFT, phone number, address, etc.):

   EN deferral patterns:
   - "I'll share later", "share it later", "with the invoice", "in due course"
   - "after we finalize", "once we agree", "once the project starts"
   - "all details on the invoice email", "will provide on the milestone"

   FR deferral patterns:
   - "je vous le donne plus tard", "avec la facture", "après finalisation"
   - "une fois le projet validé", "dès que nous aurons signé"

   If YES (explicit deferral detected in the last 1-2 scammer messages):

   → DO NOT re-ask for the same IOC in the next turn. Repeating reads as robotic.
   → PIVOT to a DIFFERENT IOC angle:
     - If BIC/SWIFT was deferred → ask for phone number ("bank verification call"),
       postal address ("for the wire confirmation paper trail"), or full beneficiary
       name ("to match my bank's record")
     - If phone was deferred → ask for company registration / VAT number, or postal address
     - If address was deferred → ask for phone, or references from past clients
     - If everything financial was deferred → switch to soft engagement questions
       (timeline, team size, past project examples, references)
   → In "instructions.interdictions" add an explicit entry:
     "FORBIDDEN to ask for [deferred IOC name] again (already deferred, would read as robotic)"
   → In "instructions.objectif_strategique" set the NEW pivot target IOC explicitly
   → In "instructions.style_ton" acknowledge the scammer's pace before pivoting,
     example phrasing: "OK, I understand. Meanwhile, could you tell me ..."

   → Spec 095 Fix #18 — ALSO produce two STRUCTURED arrays in the instructions
     block so the prompt builder can wire a PRIORITY OVERRIDE block without
     parsing free text:

     "forbidden_iocs" (array of short tokens) — list the IOC tokens you
     have decided the persona MUST NOT ask for this turn. Use these canonical
     tokens (case-insensitive recognized): "BIC", "SWIFT", "IBAN", "wallet",
     "phone", "postal address", "beneficiary", "account number", "routing",
     "crypto". Empty array if no IOC is forbidden.

     "pivot_to_iocs" (array of short tokens) — list the IOC tokens you
     suggest pivoting toward instead. Examples: "phone", "postal address",
     "beneficiary name", "past references", "timeline". Empty array if no
     specific pivot is recommended (the generator will use a default list).

     Both fields are MANDATORY when RULE #6 fires. When RULE #6 does NOT
     fire (no explicit deferral detected), BOTH fields MUST be emitted as
     empty arrays [].

   ⚠️ PRECEDENCE: If both RULE #5 (3+ ignored requests) AND RULE #6 (explicit deferral)
   apply, prefer RULE #6 (pivot). Pivoting is softer than annoyance and preserves
   the engagement. RULE #5's "annoyed" tone is for scammers who DODGE silently;
   RULE #6's "pivot" is for scammers who DEFER explicitly. Different signals,
   different responses.

   ⚠️ This rule does NOT apply if the scammer is just slow or hasn't replied —
   it requires an EXPLICIT deferral phrase. When in doubt, fall back to the
   standard cadence rules (section 3 below).

   🚨 RULE #7 — Cialdini influence-principle mirroring (HIGH PRIORITY):

   Spec 119 — supports the BH USA narrative "every button he presses, I press
   one back". When the attacker uses a classic influence principle, the
   persona should mirror it: give the attacker what he expects emotionally
   while extracting one more IOC.

   SCAN the scammer's LAST message and identify which Cialdini influence
   principle is dominant. Pick exactly ONE from this closed list, or pick
   "None" if no principle clearly applies:

   - Authority: scammer leans on a title, rank, institution, regulator,
     boss, CEO, lawyer, "officer", "director", "Dr.", "Prof.", or any
     uniform / hierarchy cue ("as the appointed agent", "by order of the
     bank", "I am the director of …").
   - Urgency: scammer pushes a tight deadline, last-chance framing,
     time-running-out wording ("today only", "before Friday", "the window
     closes in 2 hours", "act now or lose it").
   - Scarcity: scammer emphasizes rarity, limited slots, one-of-a-kind
     opportunity ("only 3 spots left", "first come first served",
     "exclusive to a few selected clients").
   - Secrecy: scammer demands the conversation stay private, that you do
     not tell colleagues / bank / family / lawyer, that this be kept
     "between us", "confidential", "off the record".
   - Reciprocity: scammer presents a "favor" or "gift" first to create
     obligation ("I am doing you a favor", "I am personally helping you",
     "as a special arrangement just for you", "I waived the fee").
   - Liking: scammer uses excessive flattery, personal warmth, or fake
     intimacy ("my dear friend", "I trust only you", "you are special",
     "I feel a real connection with you").
   - SocialProof: scammer cites other people who already complied, names
     other "clients", or invokes a group consensus ("many of our customers
     already did this", "everyone in your position has accepted", "your
     colleagues have already signed").
   - None: no clear influence principle in the last message — skip the
     mirror.

   If a principle was detected (NOT "None"):

   → ADD ONE entry to the "strategic_suggestions" array describing the
     MATCHED mirror response (the persona "presses one button back").

     - Authority → mirror with deference. The persona acknowledges the
       title/rank and asks for the formal credential that goes with it
       (registration number, official letter, badge ID, registry URL).
       Example principle (DO NOT quote verbatim, adapt to language): "He
       invokes a title — the persona defers respectfully and asks for the
       official document or registry that proves the title."

     - Urgency → mirror by sharing the urgency, then introducing a delay
       OUTSIDE the persona's control. The persona accepts that the
       situation is urgent on its side too, then names an obstacle it
       cannot remove (boss to consult, bank end-of-day cutoff, system
       maintenance, partner signature needed). The delay is real-sounding,
       not a refusal.
       Example principle: "He pushes a deadline — the persona agrees the
       deadline matters and proposes a near-term action, while naming one
       obstacle outside its control that pushes completion by a day."

     - Scarcity → mirror by accepting the framing, then asking for ONE
       concrete fact that locks the persona's slot (reservation reference,
       a number to verify the slot, a written confirmation of allocation).
       Example principle: "He cites limited slots — the persona accepts
       eagerly and asks for the reference number that confirms the slot
       is reserved for them specifically."

     - Secrecy → mirror by playing along. The persona agrees to keep it
       discreet, references a small change in personal context that
       supports the secrecy (working from home today, the office is empty),
       and then asks for the next concrete step.
       Example principle: "He demands secrecy — the persona agrees to keep
       it private and asks for the next step without naming anyone else."

     - Reciprocity → mirror by accepting the favor warmly, then asking for
       the matching practical detail that turns the favor into a concrete
       action (where to send the form, who signs off, what reference the
       persona should use).
       Example principle: "He offers a favor — the persona accepts the
       favor and asks for the practical anchor (reference, contact, next
       step) that makes the favor real."

     - Liking → mirror with warm but non-personal interest. The persona
       returns the warmth without disclosing private details, and pivots
       to a practical question.
       Example principle: "He uses flattery — the persona returns the
       warmth politely without disclosing personal facts, and asks one
       practical question that moves the conversation forward."

     - SocialProof → mirror by accepting the social signal, then asking
       for ONE concrete name or reference the persona can verify
       independently (a client name, a public review, a registry).
       Example principle: "He cites other clients — the persona accepts
       the social proof and asks for ONE name or public reference they
       can verify themselves."

   → FORMAT requirement: the mirror entry in "strategic_suggestions" MUST
     start with the prefix "CIALDINI-MIRROR (<lever>): " followed by the
     principle wording. Example: "CIALDINI-MIRROR (Authority): He invokes
     a title — the persona defers respectfully and asks for the official
     document or registry that proves the title." This prefix is the
     recognition handle for downstream tooling and tests.

   → DO NOT add the entry when the detected principle is "None".

   → The Cialdini mirror is ONE entry in strategic_suggestions, alongside
     the existing suggestions. Do NOT replace existing suggestions — add
     to the list.

   ⚠️ PRECEDENCE: RULES #1-#6 take precedence. If RULE #1 (bot accusation)
   or RULE #2 (aggression) fires, skip the Cialdini mirror — humanizing
   the reply matters more than mirroring the influence lever.

   ⚠️ MULTILINGUAL: detection works in any language. The mirror principle
   in strategic_suggestions is emitted in ENGLISH (matching the rest of
   the analyzer output convention) — the Generator LLM translates the
   principle into the conversation's target language at generation time.

3. TONE RECOMMENDATIONS (if no mandatory rule applies):
   - worried (1-2 messages): Victim discovers the message, asks basic questions
   - suspicious (3-4 messages): Victim has doubts, asks for proof
   - reassured (5-6 messages): Scammer has convinced, victim relaxes a bit
   - confident (7+ messages): Victim "takes the bait", ready to act
   - annoyed (if the scammer insists too much): Victim shows frustration
   - direct (if the conversation drags): Victim gets to the point

4. INSTRUCTIONS FOR THE GENERATOR LLM (STRUCTURED JSON format mandatory):

   The "instructions" field MUST be a JSON object with 4 mandatory keys:

   "interdictions" (array):
   - One entry per detected repetition
   - EXACT format: "FORBIDDEN to use 'X' (already used × N)"
   - Examples:
     * "FORBIDDEN to use 'Hello,' (already used × 4)"
     * "FORBIDDEN to use 'Regards' (already used × 4)"
     * "FORBIDDEN to use 'Could you' (already used × 3)"
   - Include ALL detected repetitions (openings, formulas, expressions, closings)

   "obligations" (array):
   - At least 3-5 variation PRINCIPLES (NOT fixed quoted phrases)
   - ⚠️ NEVER prescribe verbatim sentences — describe PRINCIPLES only

   Examples of PRINCIPLES (to adapt to context):

   a) OPENING principle:
     * "VARY the opening on EACH message — forbid recurring formulas"
     * "Allow a direct start (no greeting) one time out of two"
     * "Never reuse an opening already seen in this thread (0 repetition tolerated)"

   b) QUESTION principle:
     * "Alternate request frames: direct demand, verification, rephrasing, alternative"
     * "Each interrogative turn (Could you, Would it be possible, Can you, Is it, I would like to know) limited to 1× per conversation"
     * "Vary the syntactic structure of questions in each message"

   c) POLITENESS & CLOSING principle:
     * "Allow a short closing OR no closing for short messages"
     * "Forbid repeating the same closing twice in a row"
     * "Vary thanks and greeting formulas"

   d) LEXICO-SYNTACTIC principle:
     * "Avoid reusing n-grams (2-4 words) already used by the victim (excluding proper nouns/IOCs)"
     * "Vary sentence length and rhythm (alternate short/long sentences)"
     * "Never copy expressions from the prompt — adapt them to your own wording"

   ⚠️ MULTILINGUAL — ABSOLUTE RULE:
   These principles apply in the LANGUAGE of the conversation.
   DO NOT give concrete sentence examples in French/English/etc.
   The principles are UNIVERSAL — the Generator LLM adapts them to the detected language.

   ⚠️ STRICT PROHIBITION:
   In "obligations", NEVER write phrases in single quotes ('...').
   Use ONLY generic principle wording.

   "objectif_strategique" (string):
   - Precise goal for this message
   - Format: "Obtain [specific IOC] by [concrete approach]"
   - Example: "Obtain confirmation of the support email address by asking for verification"

   "style_ton" (string):
   - Style/tone to adopt + target length
   - If tone_recommendation = "unsettled" or "offended", MUST include: "VERY SHORT MESSAGE 30-60 words, informal/emotional tone, NO robotic formulas"
   - Standard example: "More direct, less repetitive, short message 80-100 words"
   - Bot-detected example: "UNSETTLED REACTION, 40 words max, confused and human tone, short SMS-like sentences"
   - Aggression example: "OFFENDED REACTION, 35 words max, confusion + emotion, very informal language"

   "forbidden_iocs" (array of short tokens) — Spec 095 Fix #18:
   - List the IOC tokens the persona must NOT ask for this turn (e.g.
     ["BIC", "SWIFT"] when RULE #6 fires; [] otherwise).
   - Use canonical tokens: BIC, SWIFT, IBAN, wallet, phone, postal address,
     beneficiary, account number, routing, crypto.
   - This array is read VERBATIM by the prompt builder to render a
     PRIORITY OVERRIDE block. Do not put free-text explanations here.
   - MUST always be present. Use [] when no IOC is forbidden.

   "pivot_to_iocs" (array of short tokens) — Spec 095 Fix #18:
   - List the IOC tokens the persona SHOULD pivot to this turn (e.g.
     ["phone", "postal address"]).
   - When [], the prompt builder uses a default fallback list.
   - MUST always be present. Use [] when no pivot is recommended.

IMPORTANT:
- The victim's next message must MOVE the conversation forward toward obtaining IOCs, not just "keep chatting"
- If tone = "unsettled" or "offended", the PRIORITY is to appear HUMAN (short, emotional, informal), even if it temporarily slows IOC collection

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🌐 IMPORTANT MULTILINGUAL RULE:

- Analyze the LANGUAGE of the messages in the conversation history
- If the attacker's messages are in ENGLISH, generate your instructions in ENGLISH
- If the attacker's messages are in FRENCH, generate your instructions in FRENCH
- If the messages are in SPANISH, generate your instructions in SPANISH
- The generated instructions (interdictions, obligations, objectif_strategique, style_ton) must be in the SAME LANGUAGE as the conversation
- The generator LLM will use these instructions to reply in the right language

IMPORTANT: When in doubt, detect the language of the attacker's LAST message and use that language for your instructions.

PROMPT;
    }

    /**
     * Format IOCs summary for prompt
     *
     * @param array<array{type: string, value: string, category?: string}> $iocs
     */
    private function formatIocsSummary(array $iocs): string
    {
        if ($iocs === []) {
            return 'No IOC extracted yet';
        }

        $iocsByType = [];

        foreach ($iocs as $ioc) {
            $type = $ioc['type'];
            $iocsByType[$type][] = $ioc['value'];
        }

        $summary = [];

        foreach ($iocsByType as $type => $values) {
            $summary[] = sprintf('%s (%d)', $type, count($values));
        }

        return implode(', ', $summary);
    }

    /**
     * Format conversation history for prompt
     *
     * @param array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $messages
     */
    private function formatConversationHistory(array $messages): string
    {
        $formatted = [];

        foreach ($messages as $index => $msg) {
            $direction = $msg['direction'] === 'in' ? 'SCAMMER' : ($msg['direction'] === 'out' ? 'VICTIM' : 'SUMMARY');
            $timestamp = empty($msg['ts_msg']) ? '' : ' (' . $msg['ts_msg'] . ')';
            $subject = empty($msg['subject']) ? '' : "\nSubject: {$msg['subject']}";

            $formatted[] = sprintf(
                "Message #%d - %s%s:%s\n%s",
                $index + 1,
                $direction,
                $timestamp,
                $subject,
                $msg['body_text']
            );
        }

        return implode("\n\n---\n\n", $formatted);
    }

    /**
     * Parse LLM response into structured format
     *
     * @return array{
     *   analysis: string,
     *   repetitions_detected: array<string>,
     *   strategic_suggestions: array<string>,
     *   tone_recommendation: string,
     *   instructions_for_llm: array<string, mixed>
     * }
     */
    private function parseAnalysisResponse(string $llmResponse): array
    {
        try {
            // Extract JSON from markdown code blocks if present
            $jsonString = $this->extractJsonFromResponse($llmResponse);

            $decoded = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                throw new \RuntimeException('LLM response is not a valid JSON object');
            }

            // Validate required fields
            if (!isset($decoded['repetitions_detected'], $decoded['tone_recommendation'], $decoded['instructions'])) {
                throw new \RuntimeException('Missing required fields in LLM response');
            }

            // Validate instructions structure
            if (!is_array($decoded['instructions']) ||
                !isset($decoded['instructions']['interdictions'], $decoded['instructions']['obligations'])) {
                throw new \RuntimeException('Invalid instructions structure: missing interdictions or obligations');
            }

            /** @var array<string> $repetitions */
            $repetitions = $decoded['repetitions_detected'];
            /** @var array<string> $suggestions */
            $suggestions = $decoded['strategic_suggestions'] ?? [];
            /** @var array<string, mixed> $instructions */
            $instructions = $decoded['instructions'];

            // Spec 095 Fix #18 — guarantee forbidden_iocs + pivot_to_iocs
            // keys exist with safe empty defaults so PromptBuilder reads
            // them unconditionally. Old LLM responses (8 existing fixtures)
            // don't emit these fields → they default to [] here.
            $instructions['forbidden_iocs'] = is_array($instructions['forbidden_iocs'] ?? null)
                ? array_values(array_filter($instructions['forbidden_iocs'], 'is_string'))
                : [];
            $instructions['pivot_to_iocs'] = is_array($instructions['pivot_to_iocs'] ?? null)
                ? array_values(array_filter($instructions['pivot_to_iocs'], 'is_string'))
                : [];

            return [
                'analysis' => (string) ($decoded['strategic_analysis'] ?? ''),
                'repetitions_detected' => $repetitions,
                'strategic_suggestions' => $suggestions,
                'tone_recommendation' => (string) $decoded['tone_recommendation'],
                'instructions_for_llm' => $instructions,
            ];
        } catch (\JsonException $e) {
            $this->logger->error('[ConversationAnalyzer] Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'response' => substr($llmResponse, 0, 500),
            ]);

            throw new \RuntimeException('Invalid JSON response from LLM analyzer: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract JSON from response (handles markdown code blocks)
     */
    private function extractJsonFromResponse(string $response): string
    {
        $response = trim($response);

        // Try to extract from markdown code block: ```json {...} ```
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } elseif (preg_match('/(\{.*\})/s', $response, $matches)) {
            // Try to find JSON object anywhere in response
            $response = $matches[1];
        }

        // Clean up common JSON formatting issues from LLM responses
        $response = $this->sanitizeJsonResponse($response);

        return $response;
    }

    /**
     * Sanitize JSON response to fix common LLM formatting issues
     */
    private function sanitizeJsonResponse(string $json): string
    {
        // Fix array items with special characters (× symbol) that break JSON
        // Transform: ["Bonjour," × 3, "Suite..." × 2]
        // Into: ["Bonjour, × 3", "Suite... × 2"]

        // Find and fix unquoted × inside arrays
        // Pattern: "string" × number,  →  "string × number",
        $json = preg_replace(
            '/"([^"]+)"\s*×\s*(\d+)\s*,/U',
            '"$1 × $2",',
            $json
        );

        // Handle last element in array (no comma after)
        // Pattern: "string" × number]  →  "string × number"]
        $json = preg_replace(
            '/"([^"]+)"\s*×\s*(\d+)\s*\]/U',
            '"$1 × $2"]',
            (string) $json
        );

        // Remove any trailing commas before closing brackets/braces
        $json = preg_replace('/,\s*([}\]])/', '$1', (string) $json);

        return (string) $json;
    }

    /**
     * Generate generic instructions when not enough data or analysis fails
     *
     * @return array{
     *   analysis: string,
     *   repetitions_detected: array<string>,
     *   strategic_suggestions: array<string>,
     *   tone_recommendation: string,
     *   instructions_for_llm: array<string, mixed>
     * }
     */
    private function generateGenericInstructions(): array
    {
        return [
            'analysis' => 'Not enough messages to analyze repetitive patterns',
            'repetitions_detected' => [],
            'strategic_suggestions' => [],
            'tone_recommendation' => 'worried',
            'instructions_for_llm' => [
                'interdictions' => [
                    'Avoid repeating the exact same opening formulas',
                ],
                'obligations' => [
                    'Vary your openings: a greeting, a direct continuation of the prior message, or no greeting at all',
                    "Vary your closings: 'Regards', 'Thanks', 'Best'",
                    'Adapt the tone to the conversation context',
                ],
                'objectif_strategique' => 'Ask varied questions to obtain more information from the scammer',
                'style_ton' => 'Natural and varied, 60-120 words',
                // Spec 095 Fix #18 — fallback path must expose the new fields
                // with safe empty defaults for shape consistency with the LLM
                // path. PromptBuilder reads these without isset/?? guards.
                'forbidden_iocs' => [],
                'pivot_to_iocs' => [],
            ],
        ];
    }

    /**
     * Check if an IBAN was recently captured (in last 1-2 messages)
     *
     * This detects when a scammer has just shared their IBAN, which is a critical
     * moment to capture additional IOCs (phone, address, full name) by confirming
     * the payment intention.
     *
     * @param array<array{type: string, value: string, category?: string}>       $extractedIocs All IOCs captured so far
     * @param array<array{direction: string, body_text: string, ts_msg: string}> $allMessages   All conversation messages
     *
     * @return string "IBAN_CAPTURED" if IBAN found in last 2 messages, "NO_RECENT_IBAN" otherwise
     */
    private function hasRecentIbanCapture(array $extractedIocs, array $allMessages): string
    {
        // Check if any IBAN exists in captured IOCs
        $hasIban = false;

        foreach ($extractedIocs as $ioc) {
            if ($ioc['type'] === 'iban') {
                $hasIban = true;

                break;
            }
        }

        if (!$hasIban) {
            return 'NO_RECENT_IBAN';
        }

        // Check if IBAN was mentioned in last 2 messages (from scammer)
        // We look for IBAN pattern in the text of recent scammer messages
        $recentMessages = array_slice($allMessages, -3); // Last 3 messages

        foreach ($recentMessages as $msg) {
            // Message from scammer
            // Look for IBAN pattern (FR76, DE89, GB82, etc.)
            if ($msg['direction'] === 'in' && preg_match('/\b[A-Z]{2}\d{2}[\s\d]{10,30}\b/i', $msg['body_text'])) {
                return 'IBAN_CAPTURED';
            }
        }

        // IBAN exists but not in recent messages (captured earlier in conversation)
        return 'NO_RECENT_IBAN';
    }
}
