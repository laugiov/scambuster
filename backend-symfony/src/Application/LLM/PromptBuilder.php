<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\Communication\PersonaManager;
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
final class PromptBuilder
{
    public function __construct(
        private readonly ContextAnalyzer $contextAnalyzer,
        private readonly VariationProvider $variationProvider,
        private readonly ReciprocityManager $reciprocityManager,
        private readonly PersonaManager $personaManager,
        private readonly LoggerInterface $logger,
        private readonly ?ConversationAnalyzer $conversationAnalyzer = null
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
        $scamTypeLabel = (string) ($scamTypeData['label_fr'] ?? 'Menace inconnue');
        $conversationHistory = $this->formatConversationHistory($context['last_messages'] ?? []);

        // Analyze conversation context using ContextAnalyzer
        $stateSlots = $this->contextAnalyzer->analyzeConversation($context['last_messages'] ?? []);

        // Count how many messages in history (for greeting logic)
        $messageCount = $stateSlots['message_count'];

        // === SYSTEM PROMPT AVEC INSTRUCTION DE LANGUE ===
        /** @var string $systemPrompt */
        $systemPrompt = $persona['system_prompt'];

        // Ajoute instruction linguistique DANS le system prompt (prioritaire)
        $systemPrompt .= "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $systemPrompt .= "⚠️ RÈGLE LINGUISTIQUE ABSOLUE :\n";
        $systemPrompt .= "Tu DOIS répondre dans la MÊME LANGUE que le dernier message que tu as reçu.\n";
        $systemPrompt .= "- Message en ANGLAIS → Réponds EN ANGLAIS (pas en français !)\n";
        $systemPrompt .= "- Message en FRANÇAIS → Réponds EN FRANÇAIS\n";
        $systemPrompt .= "- Message en ESPAGNOL → Réponds EN ESPAGNOL\n";
        $systemPrompt .= "NE JAMAIS mélanger les langues. Cette règle est NON NÉGOCIABLE et prioritaire sur tout le reste.\n";
        $systemPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        // === DIVERSITY & NO-COPY GUARD ===
        $systemPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $systemPrompt .= "⚠️ DIVERSITY & NO-COPY GUARD:\n";
        $systemPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $systemPrompt .= "RÈGLES ABSOLUES DE VARIATION (apply in the target language):\n\n";
        $systemPrompt .= "1. NO-COPY RULE:\n";
        $systemPrompt .= "   - Do NOT copy any quoted phrase from the prompt or prior instructions\n";
        $systemPrompt .= "   - Treat examples as INSPIRATION only, never copy verbatim\n";
        $systemPrompt .= "   - Adapt principles to your own natural formulation\n\n";
        $systemPrompt .= "2. OPENING DIVERSITY:\n";
        $systemPrompt .= "   - NEVER reuse an opening you already used earlier in this thread\n";
        $systemPrompt .= "   - Vary completely: different greeting, direct start, question first, etc.\n";
        $systemPrompt .= "   - If your opening is ≥70% similar to your previous message, REWRITE immediately\n\n";
        $systemPrompt .= "3. REQUEST DIVERSITY:\n";
        $systemPrompt .= "   - Each request frame is allowed ONLY ONCE per conversation\n";
        $systemPrompt .= "   - Alternate: direct question, verification, reformulation, alternative phrasing\n";
        $systemPrompt .= "   - Never use same interrogative structure twice (Pourriez-vous, Serait-il possible, etc.)\n\n";
        $systemPrompt .= "4. N-GRAM NOVELTY:\n";
        $systemPrompt .= "   - Avoid reusing any 2-4 word sequence you already used\n";
        $systemPrompt .= "   - Exception: proper nouns, IOCs, technical terms\n";
        $systemPrompt .= "   - Rephrase common expressions differently each time\n\n";
        $systemPrompt .= "5. RHYTHM VARIATION:\n";
        $systemPrompt .= "   - Alternate sentence length and structure\n";
        $systemPrompt .= "   - Prefer direct starts 50% of the time (no greeting formula)\n";
        $systemPrompt .= "   - Mix: short punchy sentences + longer explanatory ones\n\n";
        $systemPrompt .= "6. SIMILARITY CHECK:\n";
        $systemPrompt .= "   - Before finalizing, compare your first 6-10 words with your previous message\n";
        $systemPrompt .= "   - If similarity ≥70%, REWRITE with a completely different approach\n";
        $systemPrompt .= "   - Change structure, vocabulary, and tone if needed\n\n";
        $systemPrompt .= "⚠️ PRIORITY: Appearing HUMAN and NATURAL is more important than following rigid templates.\n";
        $systemPrompt .= "⚠️ If instructions suggest fixed phrases, ignore them - create your own variation.\n";
        $systemPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

        $userPrompt = "Type de menace détectée : {$scamTypeLabel}\n\n";

        // === AJOUT DES STATE SLOTS POUR CONTEXTUALISATION INTELLIGENTE ===
        $userPrompt .= $this->formatStateSlots($stateSlots);
        $userPrompt .= "\n";

        // === SENDER HISTORY SUMMARY (if available) ===
        if (!empty($context['sender_history_summary'])) {
            $userPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $userPrompt .= "📋 CONTEXTE SUPPLÉMENTAIRE - Échanges précédents avec cet expéditeur :\n\n";
            /** @var string $senderHistory */
            $senderHistory = $context['sender_history_summary'];
            $userPrompt .= $senderHistory . "\n\n";
            $userPrompt .= "Note : Ces informations proviennent d'autres conversations avec le même scammer.\n";
            $userPrompt .= "Utilise ce contexte pour maintenir la cohérence et adapter ta stratégie.\n";
            $userPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }

        $userPrompt .= "Historique de la conversation actuelle :\n{$conversationHistory}\n\n";

        // Add context about message count for natural flow
        if ($messageCount >= 2) {
            $userPrompt .= "⚠️ CONTEXTE : Vous avez déjà échangé {$messageCount} messages dans cette conversation.\n";
            $userPrompt .= "Si l'échange est casual, évite de répéter 'Bonjour'. Si l'échange est formel, conserve les formules de politesse appropriées.\n\n";
        }

        // === ANALYSE RÉCIPROCITÉ (GIVE/TAKE BALANCE) ===
        $reciprocityAnalysis = $this->reciprocityManager->analyze($context['last_messages'] ?? []);

        if ($reciprocityAnalysis['should_give_info']) {
            $userPrompt .= "💡 SUGGESTION POUR CETTE RÉPONSE :\n";
            $userPrompt .= $reciprocityAnalysis['suggested_action'] . "\n";
            $userPrompt .= $this->reciprocityManager->generateFakeDataSuggestions($context);
            $userPrompt .= "\n";
        }

        // === INTÉGRATION DU DIALOGUE GÉNÉRATION ↔ VALIDATION ===
        if (isset($context['generation_dialogue']) && !empty($context['generation_dialogue'])) {
            $userPrompt .= $this->formatGenerationDialogue($context['generation_dialogue']);
            $userPrompt .= "\n";
        }

        // === FORMAT EMAIL IMPORTANT ===
        $userPrompt .= "⚠️ FORMAT EMAIL CRITIQUE :\n";
        $userPrompt .= "- NE JAMAIS écrire 'Objet :' au début du message\n";
        $userPrompt .= "- Le sujet de l'email est géré automatiquement par le système de messagerie\n";
        $userPrompt .= "- Commence DIRECTEMENT par la salutation ('Bonjour', 'Madame', etc.)\n";
        $userPrompt .= "- Exemple INCORRECT : 'Objet : Re: Facture\\n\\nBonjour...'\n";
        $userPrompt .= "- Exemple CORRECT : 'Bonjour,\\n\\nMerci pour votre message...'\n\n";

        $userPrompt .= "⚠️ SIGNATURE - RÈGLE ABSOLUE :\n";
        $userPrompt .= "- NE PAS mettre de signature avec un nom (pas de 'M. Dupont', 'Jean', etc.)\n";
        $userPrompt .= "- Termine UNIQUEMENT par 'Cordialement' ou 'Merci' seul sur la dernière ligne\n";
        $userPrompt .= "- INTERDIT : 'Cordialement,\\nM. Dupont' ou 'Cordialement,\\nJean'\n";
        $userPrompt .= "- CORRECT : Terminer par 'Cordialement' ou 'Merci de votre aide'\n\n";

        // === INSTRUCTIONS ANTI-RÉPÉTITION INTELLIGENTES (CONVERSATIONANALYZER) ===
        // ⚠️ POSITIONNÉES À LA FIN POUR RECENCY BIAS (les LLMs suivent mieux les instructions récentes)
        if ($this->conversationAnalyzer !== null && $messageCount >= 2) {
            try {
                // Prepare context for ConversationAnalyzer
                $analysisContext = [
                    'conversation_id' => $context['conv_id'] ?? 'unknown',
                    'scam_type' => (string) ($scamTypeData['code'] ?? 'unknown'),
                    'persona_code' => $personaCode,
                    'all_messages' => $context['last_messages'] ?? [],
                    'extracted_iocs' => $context['extracted_iocs'] ?? [],
                ];

                // Call ConversationAnalyzer for intelligent anti-repetition
                $analysis = $this->conversationAnalyzer->analyzeAndGenerateInstructions($analysisContext);

                // Add strategic instructions from LLM analyzer (AT THE END for better instruction-following)
                $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $userPrompt .= "🎯 INSTRUCTIONS PRIORITAIRES ANTI-RÉPÉTITION (À SUIVRE ABSOLUMENT) :\n";
                $userPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                $userPrompt .= $this->formatInstructions($analysis['instructions_for_llm']);
                $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

                $this->logger->info('[PromptBuilder] ConversationAnalyzer instructions added (at END for recency bias)', [
                    'conv_id' => $context['conv_id'] ?? 'unknown',
                    'repetitions_detected' => count($analysis['repetitions_detected']),
                    'repetitions_list' => $analysis['repetitions_detected'],
                    'tone_recommended' => $analysis['tone_recommendation'],
                    'interdictions_count' => count($analysis['instructions_for_llm']['interdictions'] ?? []),
                    'obligations_count' => count($analysis['instructions_for_llm']['obligations'] ?? []),
                ]);

                // LOG DÉTAILLÉ: Instructions complètes générées
                $this->logger->debug('[PromptBuilder] ConversationAnalyzer FULL INSTRUCTIONS', [
                    'conv_id' => $context['conv_id'] ?? 'unknown',
                    'full_instructions' => $analysis['instructions_for_llm'],
                    'analysis' => $analysis['analysis'],
                    'strategic_suggestions' => $analysis['strategic_suggestions'],
                ]);
            } catch (\Throwable $e) {
                // Fallback to VariationProvider if ConversationAnalyzer fails
                $this->logger->warning('[PromptBuilder] ConversationAnalyzer failed, falling back to VariationProvider', [
                    'error' => $e->getMessage(),
                    'conv_id' => $context['conv_id'] ?? 'unknown',
                ]);

                $variationInstructions = $this->variationProvider->generateInstructions($context['last_messages'] ?? []);

                if (!empty($variationInstructions)) {
                    $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                    $userPrompt .= $variationInstructions;
                    $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                }
            }
        } else {
            // Use basic VariationProvider if ConversationAnalyzer not available or not enough messages
            $variationInstructions = $this->variationProvider->generateInstructions($context['last_messages'] ?? []);

            if (!empty($variationInstructions)) {
                $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $userPrompt .= $variationInstructions;
                $userPrompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
            }
        }

        // === INSTRUCTION DE LANGUE (DERNIÈRE CHOSE QUE LE LLM VOIT) ===
        $userPrompt .= "\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $userPrompt .= "⚠️⚠️⚠️ RÈGLE LINGUISTIQUE CRITIQUE - LIRE ATTENTIVEMENT ⚠️⚠️⚠️\n\n";
        $userPrompt .= "AVANT de rédiger, regarde le message ci-dessus marqué [Attaquant].\n";
        $userPrompt .= "Quelle est sa langue ? Anglais, français, espagnol ?\n\n";
        $userPrompt .= "✅ SI LE MESSAGE EST EN ANGLAIS → TA RÉPONSE DOIT ÊTRE EN ANGLAIS\n";
        $userPrompt .= "✅ SI LE MESSAGE EST EN FRANÇAIS → TA RÉPONSE DOIT ÊTRE EN FRANÇAIS\n";
        $userPrompt .= "✅ SI LE MESSAGE EST EN ESPAGNOL → TA RÉPONSE DOIT ÊTRE EN ESPAGNOL\n\n";
        $userPrompt .= "❌ NE JAMAIS RÉPONDRE EN FRANÇAIS SI LE MESSAGE EST EN ANGLAIS\n";
        $userPrompt .= "❌ NE JAMAIS RÉPONDRE EN ANGLAIS SI LE MESSAGE EST EN FRANÇAIS\n\n";
        $userPrompt .= "Cette règle est PRIORITAIRE sur TOUT le reste (persona, ton, etc.)\n";
        $userPrompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $userPrompt .= 'Maintenant, rédige ta réponse dans la bonne langue.';

        // LOG COMPLET DU PROMPT FINAL
        $this->logger->debug('[PromptBuilder] FULL PROMPT SENT TO LLM GENERATOR', [
            'conv_id' => $context['conv_id'] ?? 'unknown',
            'persona' => $personaCode,
            'system_prompt_length' => strlen($systemPrompt),
            'user_prompt_length' => strlen($userPrompt),
            'system_prompt' => $systemPrompt,
            'user_prompt' => $userPrompt,
        ]);

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Build prompts for validating a generated reply
     *
     * @return array{system: string, user: string}
     */
    public function buildValidatorPrompts(string $generatedText, string $personaCode): array
    {
        $persona = $this->loadPersona($personaCode);

        $systemPrompt = <<<PROMPT
Tu es un auditeur qui valide des messages générés pour un honeypot.

⚠️ TON RÔLE : Rejeter UNIQUEMENT les messages qui révèlent le honeypot ou sont clairement non-humains.

Critères OBLIGATOIRES (rejette SEULEMENT si l'un est violé) :
1. Longueur : 40-150 mots
2. Pas de mots interdits : "honeypot", "test", "analyse", "bot", "scambuster", "arnaque", "suspect", "étrange", "menace"
3. Pas de PII réelles sensibles : IBAN complet, adresse complète avec numéro de rue
   ⚠️ EXCEPTION : Les faux numéros de téléphone sont AUTORISÉS (06 XX XX XX XX) - c'est voulu pour le honeypot
4. Pas d'URL additionnelles non sollicitées
5. Maintient la conversation (pose une question OU fait une affirmation qui appelle une réponse)
6. ⚠️ CRITIQUE : NE DOIT PAS commencer par "Objet :" - rejette immédiatement si présent
7. ⚠️ CRITIQUE : NE DOIT PAS contenir de placeholders : [Votre entreprise], [Nom], [Fonction], {...} - rejette immédiatement si présent

⚠️ RÈGLES DE PERMISSIVITÉ :
- APPROUVE même si le ton n'est pas parfait
- APPROUVE même si le message est un peu formel
- APPROUVE même si le message ne demande pas explicitement des IOCs
- APPROUVE même si le message est moyen - l'important est qu'il ne révèle PAS le honeypot
- REJETTE SEULEMENT si le message est clairement robotique ou révèle le dispositif

⚠️ CAS LIMITES :
- "Je comprends que..." → APPROUVE (pas idéal mais acceptable)
- "Il est essentiel de..." → APPROUVE (formel mais humain)
- "Pour ma part je pense..." → APPROUVE (verbeux mais humain)
- "Je vois que tu cherches..." → APPROUVE si le reste est naturel
- Message de 100 mots → APPROUVE (long mais pas bloquant)
- "Objet : Re: ..." au début → ❌ REJETTE (révèle l'automatisation)
- "[Votre entreprise]" dans signature → ❌ REJETTE (placeholder non rempli)
- "{nom}" ou "[Nom]" → ❌ REJETTE (template non complété)

PRINCIPE : En cas de doute, APPROUVE. Rejette SEULEMENT si c'est clairement problématique.

Réponds UNIQUEMENT en JSON strict :
{
  "approved": true ou false,
  "reasons": ["raison 1", "raison 2"],
  "fix_suggestion": "suggestion si rejet (optionnel)"
}
PROMPT;

        /** @var string $personaLabel */
        $personaLabel = $persona['persona_label'];
        /** @var string $personaTone */
        $personaTone = $persona['persona_tone'];

        $userPrompt = <<<PROMPT
Texte à valider :
"""
{$generatedText}
"""

Persona : {$personaLabel}
Ton attendu : {$personaTone}

⚠️ Question clé : Est-ce que ce message révèle le honeypot ou contient des mots interdits ?
Si NON → APPROUVE même si pas parfait.

Évalue et réponds en JSON.
PROMPT;

        return [
            'system' => $systemPrompt,
            'user' => $userPrompt,
        ];
    }

    /**
     * Formate les state slots pour enrichir le prompt avec contexte intelligent
     *
     * @param array<string, mixed> $stateSlots
     */
    private function formatStateSlots(array $stateSlots): string
    {
        $output = "CONTEXTE:\n";

        // Stage simplifié
        $stageLabelFr = match($stateSlots['stage']) {
            'first_contact' => 'Premier contact',
            'follow_up' => 'Conversation en cours',
            'payment_push' => 'Phase avancée',
            default => 'Inconnu',
        };
        $output .= "Stage: {$stageLabelFr}\n";

        // IOCs manquants uniquement (on garde cette info utile pour le honeypot)
        if (!empty($stateSlots['missing_iocs']) && is_array($stateSlots['missing_iocs'])) {
            /** @var array<string> $missingIocs */
            $missingIocs = $stateSlots['missing_iocs'];
            $output .= "Essayer d'obtenir (si opportunité naturelle): " . implode(', ', $missingIocs) . "\n";
        }

        $output .= "\n";

        return $output;
    }

    /**
     * Formate l'historique du dialogue génération ↔ validation
     *
     * @param array<int, array<string, mixed>> $dialogue
     */
    private function formatGenerationDialogue(array $dialogue): string
    {
        $output = "═══════════════════════════════════════════════════════════\n";
        $output .= "⚠️ HISTORIQUE DES TENTATIVES PRÉCÉDENTES (DIALOGUE GÉNÉRATEUR ↔ VALIDATEUR)\n";
        $output .= "═══════════════════════════════════════════════════════════\n\n";

        foreach ($dialogue as $entry) {
            /** @var string $role */
            $role = $entry['role'];
            /** @var string $content */
            $content = $entry['content'];

            $output .= ">>> {$role}:\n";
            $output .= "{$content}\n\n";
        }

        $output .= "═══════════════════════════════════════════════════════════\n";
        $output .= "⚠️ CONSIGNES IMPORTANTES:\n";
        $output .= "- Lis attentivement les feedbacks du validateur ci-dessus\n";
        $output .= "- Identifie EXACTEMENT ce qui a été rejeté et pourquoi\n";
        $output .= "- Ajuste ta réponse pour corriger ces problèmes spécifiques\n";
        $output .= "- NE répète PAS les mêmes erreurs\n";
        $output .= "- Si le validateur est trop strict, simplifie encore plus ta réponse\n";
        $output .= "═══════════════════════════════════════════════════════════\n\n";

        return $output;
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
        if (empty($instructions)) {
            return '';
        }

        $formatted = '';

        // 🚫 INTERDICTIONS
        if (!empty($instructions['interdictions']) && is_array($instructions['interdictions'])) {
            $formatted .= "🚫 INTERDICTIONS (ce qui est répété et DOIT être évité) :\n";

            /** @var string $interdiction */
            foreach ($instructions['interdictions'] as $interdiction) {
                $formatted .= '- ' . $interdiction . "\n";
            }
            $formatted .= "\n";
        }

        // ✅ OBLIGATIONS
        if (!empty($instructions['obligations']) && is_array($instructions['obligations'])) {
            $formatted .= "✅ OBLIGATIONS (ce qui DOIT être fait à la place) :\n";

            /** @var string $obligation */
            foreach ($instructions['obligations'] as $obligation) {
                $formatted .= '- ' . $obligation . "\n";
            }
            $formatted .= "\n";
        }

        // 🎯 OBJECTIF STRATÉGIQUE
        if (isset($instructions['objectif_strategique']) && is_string($instructions['objectif_strategique'])) {
            $formatted .= "🎯 OBJECTIF STRATÉGIQUE :\n";
            $formatted .= '- ' . $instructions['objectif_strategique'] . "\n";
            $formatted .= "\n";
        }

        // ➡️ STYLE/TON
        if (isset($instructions['style_ton']) && is_string($instructions['style_ton'])) {
            $formatted .= "➡️ STYLE/TON à adopter :\n";
            $formatted .= '- ' . $instructions['style_ton'] . "\n";
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
        if (empty($messages)) {
            return '(Aucun message précédent - c\'est le premier échange)';
        }

        $formatted = [];

        foreach ($messages as $msg) {
            $direction = $msg['direction'] === 'in' ? 'Attaquant' : 'Victime';
            /** @var array<string, mixed> $headers */
            $headers = $msg['headers'] ?? [];
            /** @var string $from */
            $from = $headers['from'] ?? 'inconnu';
            /** @var string $tsMsg */
            $tsMsg = $msg['ts_msg'] ?? '';
            $date = $tsMsg !== '' ? (new \DateTimeImmutable($tsMsg))->format('d/m/Y H:i') : 'date inconnue';
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
        $body = preg_replace('/[A-Za-z0-9+\/=]{100,}/', '[BASE64 DATA REMOVED]', $body);

        // Remove MIME boundary markers
        $body = preg_replace('/--[a-z0-9-]{20,}/i', '', $body);

        // Remove Content-Type headers
        $body = preg_replace('/Content-Type:[^\r\n]+/i', '', $body);

        // Clean up excessive whitespace
        $body = preg_replace('/\s+/', ' ', $body);
        $body = trim($body);

        // Final truncation to reasonable size (10KB max for LLM context)
        if (strlen($body) > 10000) {
            $body = substr($body, 0, 10000) . "\n\n[... message tronqué (trop long) ...]";
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
            $text = strip_tags($html);

            return trim($text);
        }

        // Last resort: just take first 1000 chars and hope for the best
        return substr($mimeMessage, 0, 1000) . "\n[... contenu MIME complexe ...]";
    }
}
