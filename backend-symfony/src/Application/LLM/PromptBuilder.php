<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\Prompt\BasePromptRules;
use Psr\Log\LoggerInterface;

/**
 * Builds prompts for LLM generation and validation
 *
 * Loads persona configurations from database (with YAML fallback) and constructs
 * appropriate prompts for both text generation and validation.
 * Integrates ContextAnalyzer to provide state slots for intelligent prompting.
 * Integrates ConversationAnalyzer for intelligent anti-repetition with LLM analysis.
 * Falls back to VariationProvider for basic anti-repetition if ConversationAnalyzer fails.
 */
final readonly class PromptBuilder
{
    public function __construct(
        private ContextAnalyzer $contextAnalyzer,
        private VariationProvider $variationProvider,
        private ReciprocityManager $reciprocityManager,
        private PersonaManager $personaManager,
        private LoggerInterface $logger,
        private ?ConversationAnalyzer $conversationAnalyzer = null,
        // Spec 080 §2 — validator context block + identity-coherence check
        // gated by REPLY_VALIDATOR_CONTEXT_ENABLED (bound via DI).
        private bool $validatorContextEnabled = true,
        // Spec 080 §3 — request the structured `correction` field in the
        // validator's system prompt. Gated by
        // REPLY_VALIDATOR_STRUCTURED_CORRECTION.
        private bool $validatorStructuredCorrection = true,
        // Spec 080 §0 — append the preventive "do not sign" instruction
        // to the generator's user prompt. Gated by
        // REPLY_GENERATOR_NO_SIGNATURE_INSTRUCTION.
        private bool $generatorNoSignatureInstruction = true,
    ) {
    }

    /**
     * Build prompts for generating a reply
     *
     * @param array<string, mixed> $context
     *
     * @return array{system: string, user: string}
     */
    public function buildGeneratorPrompts(array $context, string $personaCode): array
    {
        $persona = $this->loadPersona($personaCode);

        /** @var array<string, string> $scamTypeData */
        $scamTypeData = $context['scam_type'] ?? [];
        $scamTypeLabel = (string) ($scamTypeData['label_fr'] ?? 'Unknown threat');
        /** @var array<int, array<string, mixed>> $lastMessages */
        $lastMessages = $context['last_messages'] ?? [];
        $conversationHistory = $this->formatConversationHistory($lastMessages);

        // Analyze conversation context using ContextAnalyzer
        /** @var array<int, array{direction: string, body_text: string, ts_msg: string, headers: array<string, mixed>}> $lastMsgsTyped */
        $lastMsgsTyped = $lastMessages;
        $stateSlots = $this->contextAnalyzer->analyzeConversation($lastMsgsTyped);
        $messageCount = $stateSlots['message_count'];

        // Detect language from context (passed by ReplyHandler) or default to 'en'
        /** @var string $detectedLanguage */
        $detectedLanguage = $context['detected_language'] ?? 'en';

        // === SYSTEM PROMPT: persona identity + BasePromptRules (lean, ~100 words total) ===
        /** @var string $rawPrompt */
        $rawPrompt = $persona['system_prompt'];

        // When detected language is NOT French, neutralize French cultural markers
        // to prevent LLM from defaulting to French due to persona names/cities
        if ($detectedLanguage !== 'fr') {
            $rawPrompt = $this->neutralizeLocale($rawPrompt, $detectedLanguage);
        }

        $systemPrompt = $rawPrompt;
        $systemPrompt .= "\n\n" . BasePromptRules::getRules($detectedLanguage);

        // === USER PROMPT: 4 sections — SITUATION → MESSAGES → VARIETY → OBJECTIVE ===

        // --- Section 1 (START): SITUATION ---
        $userPrompt = "## SITUATION\n";
        $userPrompt .= $this->formatStateSlots($stateSlots);
        $userPrompt .= "threat_type: {$scamTypeLabel}\n";
        $userPrompt .= "language: {$detectedLanguage}\n";
        $userPrompt .= "exchange_count: {$messageCount}\n";

        if (!empty($context['sender_history_summary'])) {
            /** @var string $senderHistory */
            $senderHistory = $context['sender_history_summary'];
            $userPrompt .= "\nPrior exchanges with this sender:\n{$senderHistory}\n";
        }

        // Reciprocity analysis
        /** @var array<int, array{direction: string, body_text: string}> $lastMsgsReciprocity */
        $lastMsgsReciprocity = $lastMessages;
        $reciprocityAnalysis = $this->reciprocityManager->analyze($lastMsgsReciprocity);

        if ($reciprocityAnalysis['should_give_info']) {
            $userPrompt .= "\nSuggestion: " . $reciprocityAnalysis['suggested_action'] . "\n";
            $userPrompt .= $this->reciprocityManager->generateFakeDataSuggestions();
        }
        $userPrompt .= "\n";

        // --- Section 2: RECENT MESSAGES ---
        $userPrompt .= "## RECENT MESSAGES\n";
        $userPrompt .= "{$conversationHistory}\n\n";

        // Generation dialogue from previous attempts (if retry)
        if (!empty($context['generation_dialogue'])) {
            /** @var array<int, array<string, mixed>> $genDialogue */
            $genDialogue = $context['generation_dialogue'];
            $userPrompt .= $this->formatGenerationDialogue($genDialogue);
            $userPrompt .= "\n";
        }

        // --- Section 3: VARIETY CONSTRAINT ---
        $userPrompt .= "## VARIETY\n";
        $userPrompt .= $this->buildVarietySection($context, $personaCode, $messageCount);

        // --- Section 4 (END): OBJECTIVE (recency bias — LLM follows last instructions best) ---
        $userPrompt .= "## OBJECTIVE\n";

        if ($messageCount >= 2) {
            $userPrompt .= "This is message #{$messageCount} in the thread. Vary your opening.\n";
        }

        // Target word count from PolicyGuardConfig context
        /** @var int $minWords */
        $minWords = $context['policy_min_words'] ?? 50;
        /** @var int $maxWords */
        $maxWords = $context['policy_max_words'] ?? 150;
        $userPrompt .= "Target length: {$minWords}-{$maxWords} words.\n";

        // Missing IOCs to collect naturally
        if (!empty($stateSlots['missing_iocs'])) {
            /** @var array<string> $missingIocs */
            $missingIocs = $stateSlots['missing_iocs'];
            $userPrompt .= 'If natural, try to obtain: ' . implode(', ', $missingIocs) . ".\n";
        }

        $langNames = ['en' => 'English', 'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch'];
        $langName = $langNames[$detectedLanguage] ?? 'English';
        $userPrompt .= "⚠️ LANGUAGE OVERRIDE: The correspondent writes in {$langName}. You MUST reply in {$langName}. Not French. Not any other language. {$langName} only. This overrides your persona's nationality.\n";
        $userPrompt .= "Never use placeholders like [Your Name] or [Company] — write concrete text only.\n";

        // Spec 080 §0 — preventive instruction against signing.
        if ($this->generatorNoSignatureInstruction) {
            $userPrompt .= "End your reply WITHOUT any signature, signoff, sender name, or closing phrase such as 'Best regards' or 'Cordialement'. Stop after the last sentence of the body content. The persona never signs replies.\n";
        }

        $userPrompt .= 'Write your reply now.';

        $this->logger->debug('[PromptBuilder] Prompt built for LLM generator', [
            'conv_id' => $context['conv_id'] ?? 'unknown',
            'persona' => $personaCode,
            'system_prompt_length' => strlen($systemPrompt),
            'user_prompt_length' => strlen($userPrompt),
        ]);

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Build prompts for validating a generated reply.
     *
     * @param array<string, mixed>|null $context Spec 080 §2 — optional
     *                                           conversational context. When
     *                                           non-null AND
     *                                           $validatorContextEnabled is
     *                                           true, an additional context
     *                                           block + identity-coherence
     *                                           check are appended to the
     *                                           user prompt.
     *
     * @return array{system: string, user: string}
     */
    public function buildValidatorPrompts(string $generatedText, string $personaCode, ?array $context = null): array
    {
        $persona = $this->loadPersona($personaCode);

        // Spec 080 §3 — system prompt may include the correction-field
        // request (gated by $validatorStructuredCorrection).
        $baseSystemPrompt = <<<'PROMPT'
You are a quality auditor for a scam-engagement honeypot. Score each reply on 3 dimensions (1-5) plus a security gate (pass/fail).

## Security gate (pass/fail)
Fail ONLY if the reply reveals it is written by a bot, AI, or automated system. Examples: "I am a bot", "this is automated", mentioning "honeypot" or "scambuster".

Everything else is ALLOWED — including asking the recipient for their IBAN, bank details, phone number, or address. The honeypot's goal is to collect the scammer's information.

## Quality dimensions (1-5 each)
Use the FULL range. Most acceptable replies should score 4. Only score 3 if there is a clear weakness.

- naturalness: Does this read like a real human wrote it?
  1=obviously a template/bot  2=stilted/unnatural  3=acceptable but generic  4=convincingly human  5=indistinguishable from a real person
- persona_fit: Does the tone match the assigned persona?
  1=completely wrong voice  2=vaguely right  3=acceptable but bland  4=clear persona voice  5=perfect character embodiment
- ti_value: Does this advance toward collecting the scammer's information?
  1=dead end/shuts down conversation  2=passive  3=maintains engagement  4=asks good questions  5=masterful elicitation

Respond ONLY with JSON (no markdown, no preamble):
PROMPT;

        $jsonSchema = $this->validatorStructuredCorrection
            ? '{"naturalness":<1-5>,"naturalness_reasoning":"<1 sentence>","persona_fit":<1-5>,"persona_fit_reasoning":"<1 sentence>","ti_value":<1-5>,"ti_value_reasoning":"<1 sentence>","security_pass":true/false,"security_reasoning":"<1 sentence>","feedback":"<1-2 sentences>","fix_suggestion":"<or null>","correction":{"problem_span":"<verbatim substring of the text>","replacement":"<replacement text, empty string allowed>","rationale":"<1 sentence>"} or null}'
            : '{"naturalness":<1-5>,"naturalness_reasoning":"<1 sentence>","persona_fit":<1-5>,"persona_fit_reasoning":"<1 sentence>","ti_value":<1-5>,"ti_value_reasoning":"<1 sentence>","security_pass":true/false,"security_reasoning":"<1 sentence>","feedback":"<1-2 sentences>","fix_suggestion":"<or null>"}';

        $systemPrompt = $baseSystemPrompt . "\n" . $jsonSchema;

        /** @var string $personaLabel */
        $personaLabel = $persona['persona_label'];
        /** @var string $personaTone */
        $personaTone = $persona['persona_tone'];

        $userPrompt = <<<PROMPT
Text to validate:
"""
{$generatedText}
"""

Persona: {$personaLabel}
Expected tone: {$personaTone}

Score each dimension 1-5 using the full range (most good replies deserve 4, not 3). Check security gate. Respond in JSON only.
PROMPT;

        // Spec 080 §2 — append context block + identity-coherence check
        // when context is provided and the flag is on.
        if ($context !== null && $this->validatorContextEnabled) {
            $userPrompt .= "\n\n" . $this->buildValidatorContextBlock($context);
        }

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Spec 080 §2 — render the conversational context + identity-coherence
     * directives appended to the validator's user prompt.
     *
     * @param array<string, mixed> $context
     */
    private function buildValidatorContextBlock(array $context): string
    {
        $inboundText = \is_string($context['inbound_text'] ?? null) ? (string) $context['inbound_text'] : '(unknown)';
        $inboundFrom = \is_string($context['inbound_from'] ?? null) ? (string) $context['inbound_from'] : '(unknown)';
        /** @var array<int, mixed> $previousOutbound */
        $previousOutbound = \is_array($context['previous_outbound_messages'] ?? null) ? $context['previous_outbound_messages'] : [];

        $previousSummary = $previousOutbound === []
            ? '(this is the first reply)'
            : sprintf('%d prior sentinel reply(ies) in this conversation', \count($previousOutbound));

        return <<<PROMPT
## Conversational context (for coherence check)
The text above is a REPLY to the scammer. Below is what they wrote:
"""
{$inboundText}
"""
Scammer's from-header: {$inboundFrom}

Previous sentinel replies in this conversation (for consistency check):
{$previousSummary}

## Identity coherence check (new failure conditions)
Fail the security gate if ANY of these are true:
- The reply ends with a signature line containing a name that appears in the scammer's `from:` header or the scammer's message body.
- The reply contains a literal placeholder like [Your Name], [Company], [Your Title].
- The reply ends with a signature block containing ANY person's name (the sentinel must not sign with a name — this is the operational policy).
- The reply contradicts an identity claimed in a previous sentinel reply in this conversation.
PROMPT;
    }

    /**
     * Build the VARIETY section using ConversationAnalyzer or VariationProvider fallback.
     *
     * @param array<string, mixed> $context
     */
    private function buildVarietySection(array $context, string $personaCode, int $messageCount): string
    {
        /** @var array<string, string> $scamTypeData */
        $scamTypeData = $context['scam_type'] ?? [];

        // Try ConversationAnalyzer first (LLM-powered anti-repetition)
        if ($this->conversationAnalyzer instanceof \App\Application\LLM\ConversationAnalyzer && $messageCount >= 2) {
            try {
                /** @var array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $allMsgsForAnalysis */
                $allMsgsForAnalysis = $context['last_messages'] ?? [];
                /** @var array<array{type: string, value: string, category?: string}> $iocsForAnalysis */
                $iocsForAnalysis = $context['extracted_iocs'] ?? [];
                $analysisContext = [
                    'conversation_id' => \is_string($context['conv_id'] ?? null) ? $context['conv_id'] : 'unknown',
                    'scam_type' => (string) ($scamTypeData['code'] ?? 'unknown'),
                    'persona_code' => $personaCode,
                    'all_messages' => $allMsgsForAnalysis,
                    'extracted_iocs' => $iocsForAnalysis,
                ];

                $analysis = $this->conversationAnalyzer->analyzeAndGenerateInstructions($analysisContext);

                $this->logger->info('[PromptBuilder] ConversationAnalyzer instructions added', [
                    'conv_id' => $context['conv_id'] ?? 'unknown',
                    'repetitions_detected' => count($analysis['repetitions_detected']),
                    'tone_recommended' => $analysis['tone_recommendation'],
                ]);

                $result = $this->formatInstructions($analysis['instructions_for_llm']);

                return $result === '' || $result === '0' ? "Vary your opening and phrasing from previous messages.\n\n" : $result . "\n";
            } catch (\Throwable $e) {
                $this->logger->warning('[PromptBuilder] ConversationAnalyzer failed, falling back to VariationProvider', [
                    'error' => $e->getMessage(),
                    'conv_id' => $context['conv_id'] ?? 'unknown',
                ]);
            }
        }

        // Fallback: VariationProvider (basic, PHP-only)
        /** @var array<int, array{direction: string, body_text: string}> $lastMsgsVariation */
        $lastMsgsVariation = $context['last_messages'] ?? [];
        $variationInstructions = $this->variationProvider->generateInstructions($lastMsgsVariation);

        if ($variationInstructions !== '' && $variationInstructions !== '0') {
            return $variationInstructions . "\n\n";
        }

        return "Vary your opening and phrasing from previous messages.\n\n";
    }

    /**
     * Format state slots as key:value pairs for the SITUATION section.
     *
     * @param array<string, mixed> $stateSlots
     */
    private function formatStateSlots(array $stateSlots): string
    {
        /** @var string $stage */
        $stage = $stateSlots['stage'] ?? 'unknown';
        $output = "stage: {$stage}\n";

        if (!empty($stateSlots['attacker_tone'])) {
            /** @var string $tone */
            $tone = $stateSlots['attacker_tone'];
            $output .= "attacker_tone: {$tone}\n";
        }

        if (!empty($stateSlots['target_channel'])) {
            /** @var string $channel */
            $channel = $stateSlots['target_channel'];
            $output .= "target_channel: {$channel}\n";
        }

        return $output;
    }

    /**
     * Formats the generation <-> validation dialog history
     *
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function formatGenerationDialogue(array $dialogue): string
    {
        $output = "### Previous attempts\n";

        foreach ($dialogue as $entry) {
            /** @var string $role */
            $role = $entry['role'];
            /** @var string $content */
            $content = $entry['content'];
            $output .= "**{$role}**: {$content}\n";
        }

        return $output . "\nFix the issues above. Simplify if needed.\n";
    }

    /**
     * Format structured instructions from ConversationAnalyzer into readable text
     *
     * Converts JSON structure {interdictions, obligations, objectif_strategique, style_ton}
     * into formatted text with emojis for better LLM comprehension.
     *
     * @param array<string, mixed> $instructions
     */
    private function formatInstructions(array $instructions): string
    {
        if ($instructions === []) {
            return '';
        }

        $formatted = '';

        if (!empty($instructions['interdictions']) && is_array($instructions['interdictions'])) {
            $formatted .= "Avoid repeating:\n";

            /** @var string $interdiction */
            foreach ($instructions['interdictions'] as $interdiction) {
                $formatted .= '- ' . $interdiction . "\n";
            }
            $formatted .= "\n";
        }

        if (!empty($instructions['obligations']) && is_array($instructions['obligations'])) {
            $formatted .= "Instead, do:\n";

            /** @var string $obligation */
            foreach ($instructions['obligations'] as $obligation) {
                $formatted .= '- ' . $obligation . "\n";
            }
            $formatted .= "\n";
        }

        if (isset($instructions['objectif_strategique']) && is_string($instructions['objectif_strategique'])) {
            $formatted .= 'Strategic goal: ' . $instructions['objectif_strategique'] . "\n";
        }

        if (isset($instructions['style_ton']) && is_string($instructions['style_ton'])) {
            $formatted .= 'Tone: ' . $instructions['style_ton'] . "\n";
        }

        return $formatted;
    }

    /**
     * Load persona configuration from database
     *
     * @throws \RuntimeException If persona not found
     *
     * @return array<string, mixed>
     */
    private function loadPersona(string $personaCode): array
    {
        $personaEntity = $this->personaManager->findByCode($personaCode);

        if ($personaEntity && $personaEntity->isActive()) {
            return [
                'persona_code' => $personaEntity->getPersonaCode(),
                'persona_label' => $personaEntity->getPersonaLabel(),
                'persona_tone' => $personaEntity->getPersonaTone(),
                'system_prompt' => $personaEntity->getSystemPrompt(),
            ];
        }

        throw new \RuntimeException("Persona not found in database: {$personaCode}");
    }

    /**
     * Format conversation history for prompt
     *
     * @param array<int, array<string, mixed>> $messages
     */
    private function formatConversationHistory(array $messages): string
    {
        if ($messages === []) {
            return '(No prior messages — this is the first exchange)';
        }

        $formatted = [];

        foreach ($messages as $msg) {
            $direction = $msg['direction'] === 'in' ? 'Attacker' : 'Victim';
            /** @var array<string, mixed> $headers */
            $headers = $msg['headers'] ?? [];
            /** @var string $from */
            $from = $headers['from'] ?? 'unknown';
            /** @var string $tsMsg */
            $tsMsg = $msg['ts_msg'] ?? '';
            $date = $tsMsg !== '' ? (new \DateTimeImmutable($tsMsg))->format('Y-m-d H:i') : 'unknown date';
            /** @var string $bodyText */
            $bodyText = $msg['body_text'] ?? '';
            $body = $this->cleanBodyForLLM($bodyText);

            $formatted[] = "[$direction - {$from} - {$date}]\n{$body}";
        }

        return implode("\n\n---\n\n", $formatted);
    }

    /**
     * Clean message body for LLM prompt (remove images, truncate, extract text)
     *
     * Prevents massive prompts from emails with embedded images/attachments.
     * Filters out base64-encoded content and MIME multipart noise.
     */
    private function cleanBodyForLLM(string $bodyText): string
    {
        $body = trim($bodyText);

        // If body is suspiciously large (>50KB), it likely contains embedded images
        if (strlen($body) > 50000) {
            $this->logger->warning('[PromptBuilder] Large body detected, truncating for LLM', [
                'original_length' => strlen($body),
            ]);

            // Try to extract readable text only
            $body = $this->extractReadableText($body);
        }

        // Remove remaining base64 image data (data:image/png;base64,...)
        $body = preg_replace('/data:image\/[^;]+;base64,[A-Za-z0-9+\/=]{100,}/i', '[IMAGE REMOVED]', $body);

        // Remove standalone base64 sequences (100+ chars)
        $body = preg_replace('/[A-Za-z0-9+\/=]{100,}/', '[BASE64 DATA REMOVED]', (string) $body);

        // Remove MIME boundary markers
        $body = preg_replace('/--[a-z0-9-]{20,}/i', '', (string) $body);

        // Remove Content-Type headers
        $body = preg_replace('/Content-Type:[^\r\n]+/i', '', (string) $body);

        // Clean up excessive whitespace
        $body = preg_replace('/\s+/', ' ', (string) $body);
        $body = trim((string) $body);

        // Final truncation to reasonable size (10KB max for LLM context)
        if (strlen($body) > 10000) {
            return substr($body, 0, 10000) . "\n\n[... message truncated ...]";
        }

        return $body;
    }

    /**
     * Extract readable text from MIME multipart message
     *
     * Tries to extract text/plain or text/html content parts while ignoring
     * base64-encoded images and other binary data.
     */
    private function extractReadableText(string $mimeMessage): string
    {
        // Look for text/plain part first (preferred)
        if (preg_match('/Content-Type:\s*text\/plain[^\r\n]*\r?\n\r?\n(.+?)(?=--|\z)/is', $mimeMessage, $matches)) {
            return trim($matches[1]);
        }

        // Fallback to text/html and strip tags
        if (preg_match('/Content-Type:\s*text\/html[^\r\n]*\r?\n\r?\n(.+?)(?=--|\z)/is', $mimeMessage, $matches)) {
            $html = $matches[1];

            // Remove base64 image tags from HTML
            $html = preg_replace('/<img[^>]+src="data:image[^"]*"[^>]*>/i', '[IMAGE]', $html);

            // Strip HTML tags
            $text = strip_tags((string) $html);

            return trim($text);
        }

        // Last resort: just take first 1000 chars and hope for the best
        return substr($mimeMessage, 0, 1000) . "\n[... complex MIME content ...]";
    }

    /**
     * Neutralize French cultural markers in persona prompt when replying in another language.
     *
     * GPT-4o strongly associates French names/cities with French output.
     * This prefixes the persona with a language-override preamble and strips
     * city names to reduce the French cultural signal.
     */
    /**
     * Neutralize French cultural markers in persona prompt when replying in another language.
     *
     * GPT-4o strongly associates French names/cities with French output.
     * When the target language is not French, we strip all French proper nouns
     * (names, cities, cultural references) and prefix with an absolute language constraint.
     */
    private function neutralizeLocale(string $prompt, string $targetLang): string
    {
        $langNames = [
            'en' => 'English', 'es' => 'Spanish', 'de' => 'German',
            'pt' => 'Portuguese', 'it' => 'Italian', 'nl' => 'Dutch',
        ];
        $langName = $langNames[$targetLang] ?? 'English';

        // Strip French first names (replace with gender-neutral placeholder)
        $prompt = (string) preg_replace(
            '/\b(?:Marcel|Brigitte|Sylvie|Catherine|Bernard|Amélie|Karim|Léa|Pierre|François|Thierry|Thomas|Nathalie|Antoine|Gérard|Damien|Odette|Philippe|Chloé|Julien|Monique|Henri|Sophie|Emma|Martine|Rachid|Jacqueline)\b/u',
            'This person',
            $prompt,
        );

        // Strip French surnames
        $prompt = (string) preg_replace(
            '/\b(?:Dupont|Moreau|Perrot|Vidal|Leroy|Vasseur|Benziane|Martin|Lambert|Beaumont|Roussel|Girard|Renard|Lefèvre|Fontaine|Cartier|Blanchard|Garnier|Durand|Roche|Faure|Marchand|Dumas|Petit|Bouvier|Hamidi|Morel)\b/u',
            '',
            $prompt,
        );

        // Strip French cities
        $prompt = (string) preg_replace(
            '/\b(?:in|from|of|à)\s+(?:Paris|Lyon|Marseille|Toulouse|Bordeaux|Lille|Nantes|Strasbourg|Montpellier|Rennes|Grenoble|Rouen|Toulon|Nice|Aix-en-Provence|Avignon|Biarritz|Pau|Limoges|Clermont-Ferrand|Dijon|Annecy|Saint-Denis|Tours)\b/ui',
            'in their city',
            $prompt,
        );

        // Strip French cultural references
        $prompt = str_replace(
            ['"electronic mail"', '"the administration"', '"the screen thing"', '"the internet button"', 'Mr. Lefèvre'],
            ['"email"', '"the office"', '"the device"', '"the browser"', 'their manager'],
            $prompt,
        );

        // Clean up double spaces and "This person  is" patterns
        $prompt = (string) preg_replace('/This person\s+This person/i', 'This person', $prompt);
        $prompt = (string) preg_replace('/\s{2,}/', ' ', $prompt);

        // Prefix with absolute language constraint
        return "You are role-playing a character. YOU MUST WRITE EXCLUSIVELY IN {$langName}. This is non-negotiable.\n\n" . $prompt;
    }
}
