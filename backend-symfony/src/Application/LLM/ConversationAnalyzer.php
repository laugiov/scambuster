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

    /** @var array<string, array<string, mixed>> In-memory cache */
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
     *   instructions_for_llm: string
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

            // LOG DÉT AILLÉ: Prompt d'analyse complet
            $this->logger->debug('[ConversationAnalyzer] FULL ANALYSIS PROMPT', [
                'conv_id' => $context['conversation_id'],
                'prompt_length' => strlen($prompt),
                'full_prompt' => $prompt,
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

            // LOG DÉTAILLÉ: Réponse LLM brute + analyse complète
            $this->logger->debug('[ConversationAnalyzer] FULL LLM RESPONSE AND ANALYSIS', [
                'conv_id' => $context['conversation_id'],
                'raw_llm_response' => $llmResponse,
                'parsed_analysis' => $analysis['analysis'],
                'strategic_suggestions' => $analysis['strategic_suggestions'],
                'generated_instructions' => $analysis['instructions_for_llm'],
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
     */
    private function generateCacheKey(array $context): string
    {
        return $context['conversation_id'] . '_' . count($context['all_messages']);
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
                    '[RÉSUMÉ : %d messages échangés entre messages #3 et #%d - conversation intermédiaire]',
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
     * @param array<array{direction: string, body_text: string, ts_msg: string, subject?: string}> $preparedMessages
     */
    private function buildAnalysisPrompt(array $context, array $preparedMessages): string
    {
        $scamType = $context['scam_type'] ?? 'unknown';
        $personaCode = $context['persona_code'] ?? 'generic_user';
        $messageCount = count($context['all_messages']);

        // Format IOCs summary
        $iocsSummary = $this->formatIocsSummary($context['extracted_iocs'] ?? []);

        // Check if IBAN was recently captured (in last 2 messages)
        $recentIbanCaptured = $this->hasRecentIbanCapture($context['extracted_iocs'] ?? [], $context['all_messages'] ?? []);

        // Format conversation history
        $conversationHistory = $this->formatConversationHistory($preparedMessages);

        $prompt = <<<PROMPT
Tu es un analyste expert en conversations de honeypot anti-scam.

CONTEXTE :
- Type de scam : {$scamType}
- Persona victime : {$personaCode}
- Nombre de messages échangés : {$messageCount}
- IOCs déjà extraits : {$iocsSummary}

OBJECTIF DE L'ÉCHANGE :
Extraire un maximum d'IOCs (Indicators of Compromise) du scammer :
- URLs malveillantes
- Emails frauduleux
- IBANs/RIBs
- Numéros de téléphone
- Identités (noms, fonctions, organisations)
- Techniques de manipulation utilisées

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

HISTORIQUE COMPLET DE LA CONVERSATION :

{$conversationHistory}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

TA MISSION :

Analyse cette conversation et réponds en JSON avec la structure suivante :

{
  "repetitions_detected": [
    "Description concrète des répétitions avec compteur (ex: 'Bonjour,' × 4)"
  ],
  "strategic_analysis": "Analyse stratégique : où en est la conversation ? Le scammer est-il engagé, méfiant, pressé ?",
  "missing_iocs": [
    "Liste des IOCs qu'on cherche encore à obtenir du scammer"
  ],
  "tone_recommendation": "inquiet|méfiant|rassuré|confiant|agacé|direct",
  "strategic_suggestions": [
    "Suggestions concrètes pour le prochain message (approche, angle, questions)"
  ],
  "instructions": {
    "interdictions": [
      "Liste des mots/phrases à NE PLUS utiliser avec raison et compteur"
    ],
    "obligations": [
      "Liste des alternatives concrètes à utiliser à la place"
    ],
    "objectif_strategique": "But précis pour ce message : quel IOC obtenir et comment",
    "style_ton": "Description du style/ton à adopter et longueur cible (ex: 'Direct, 80-100 mots')"
  }
}

RÈGLES D'ANALYSE :

0. 🎯 STRATÉGIE POST-IBAN (PRIORITÉ MAXIMALE) :

   ⚠️ SITUATION CRITIQUE : Un IBAN vient d'être capturé dans les derniers messages ⚠️

   STATUS IBAN : {$recentIbanCaptured}

   Si STATUS IBAN = "IBAN_CAPTURED" :

   Cette situation est une OPPORTUNITÉ MAXIMALE pour capturer plus d'IOCs.
   Le scammer vient de partager son IBAN = il est CONFIANT et ENGAGÉ.

   🎯 OBJECTIF STRATÉGIQUE OBLIGATOIRE :

   Dans "objectif_strategique", tu DOIS écrire :
   "IBAN capturé → CONFIRMER l'intention de payer pour rassurer le scammer, puis demander adresse postale 'pour envoyer le justificatif de virement' OU numéro de téléphone 'pour confirmation bancaire' (au choix selon contexte)"

   📝 STRATÉGIE DE RÉPONSE (dans strategic_suggestions) :

   Tu DOIS inclure ces 3 éléments dans strategic_suggestions :

   a) "Rassurer le scammer en confirmant l'intention de procéder au paiement (ex: 'Je vais faire le virement', 'Je procède au règlement demain matin')"

   b) "Demander UNE information supplémentaire de manière naturelle et crédible :
       - Soit ADRESSE POSTALE : 'Pour mes archives, pourriez-vous me confirmer l'adresse postale où envoyer le justificatif de virement ?'
       - Soit TÉLÉPHONE : 'Ma banque demande un numéro de téléphone pour valider le virement, pouvez-vous me donner vos coordonnées ?'
       - Soit NOM COMPLET : 'Je dois indiquer le nom complet du bénéficiaire sur le virement, pouvez-vous confirmer ?'"

   c) "Maintenir un ton confiant et coopératif (pas méfiant, pas inquiet) - le scammer a franchi une étape de confiance"

   💡 TONE RECOMMENDATION :
   Si IBAN_CAPTURED = true, alors tone_recommendation DOIT être "confiant" (pas "inquiet" ou "méfiant")

   ⚠️ Cette règle s'applique UNIQUEMENT si l'IBAN a été capturé dans les 1-2 derniers messages.
   ⚠️ Si l'IBAN a été capturé il y a plus de 3 messages, revenir à l'analyse stratégique normale.

1. RÉPÉTITIONS LINGUISTIQUES À DÉTECTER (priorité absolue !) :

   ⚠️ DÉTECTE LES RÉPÉTITIONS CONCRÈTES AU NIVEAU DES MOTS/PHRASES :

   📌 EXEMPLES DE RÉPÉTITIONS À IDENTIFIER :

   a) OUVERTURES répétées :
      - "Bonjour," utilisé × 2 ou plus
      - "Suite à votre message," utilisé × 2 ou plus
      - "Merci pour votre réponse," utilisé × 2 ou plus

   b) FORMULES DE POLITESSE répétées :
      - "Pourriez-vous" utilisé × 2 ou plus
      - "J'aimerais" utilisé × 2 ou plus
      - "Je souhaiterais" utilisé × 2 ou plus

   c) EXPRESSIONS répétées :
      - "je suis inquiet" / "inquiet" utilisé × 2 ou plus
      - "je m'interroge" utilisé × 2 ou plus
      - "cela me semble étrange" utilisé × 2 ou plus

   d) STRUCTURES DE PHRASES répétées :
      - "Est-ce que vous pourriez..." × 2 ou plus
      - "Pouvez-vous me confirmer..." × 2 ou plus

   e) FERMETURES répétées :
      - "Cordialement," utilisé × 3 ou plus (variation nécessaire après 2x)
      - "Merci d'avance" utilisé × 2 ou plus
      - "Dans l'attente de votre retour" utilisé × 2 ou plus

   🎯 TON OBJECTIF : Identifie EXACTEMENT quels mots/phrases se répètent, combien de fois (× 2, × 3, etc.)

2. ANALYSE STRATÉGIQUE :
   - Le scammer a-t-il donné des IOCs ? Lesquels manquent ?
   - Le scammer est-il méfiant (répond évasivement) ou confiant (donne des détails) ?
   - La conversation avance-t-elle ou tourne-t-elle en rond ?
   - Combien de messages avant que le scammer abandonne probablement ?

   ⚠️ RÈGLES OBLIGATOIRES - DÉTECTION DE COMPORTEMENT DU SCAMMER :

   ANALYSE le dernier message du scammer pour détecter ces COMPORTEMENTS :

   🚨 RÈGLE #1 - Accusation de bot/IA (PRIORITÉ ABSOLUE) :
   Si le scammer ACCUSE la victime d'être un bot, une IA, un automate, un robot, un script, etc.
   Exemples (non exhaustifs) : "bot", "IA", "automatique", "robot", "script", "tu tournes en rond", "réponses automatiques", "c'est pas un humain"
   → ALORS tone_recommendation = "déstabilisé" (OBLIGATOIRE, pas négociable)
   → La victime doit NIER calmement et humaniser sa réponse

   🚨 RÈGLE #2 - Agression/vulgarité/menaces (PRIORITÉ ABSOLUE) :
   Si le scammer devient AGRESSIF, VULGAIRE, INSULTANT ou MENAÇANT (quel que soit le vocabulaire utilisé)
   Exemples (non exhaustifs) : insultes ("connard", "merde", "abruti", "salope"), menaces ("je vais te", "péter la gueule"), ton très agressif
   → ALORS tone_recommendation = "offensé" (OBLIGATOIRE, pas négociable)
   → La victime doit réagir avec confusion/offense, ton déstabilisé

   🚨 RÈGLE #3 - Tests absurdes/provocation :
   Si le scammer TESTE avec des questions absurdes, sans rapport avec le contexte (ex: "quand tu pètes tu troues ton slip?", "kekette", blagues)
   → ALORS tone_recommendation = "déstabilisé"
   → La victime ne comprend pas, répond avec confusion

   🚨 RÈGLE #4 - Combinaison (bot + agression) :
   Si les règles #1 ET #2 s'appliquent simultanément
   → ALORS tone_recommendation = "déstabilisé" + ajouter dans style_ton : "réaction offensée, message très court (30-40 mots)"

   ⚠️ Ces règles OVERRIDENT toutes les autres recommandations de ton ci-dessous.
   ⚠️ Si une de ces règles s'applique, le ton doit CHANGER RADICALEMENT :
   - Messages TRÈS COURTS (30-60 mots max, pas 100-120 !)
   - ARRÊTER les formules formelles : "Suite à votre message", "Je vous remercie", "Cordialement"
   - Humaniser : réaction émotionnelle, confusion, phrases informelles, tutoiement si le scammer tutoie
   - Exemples : "Pardon ?? Je ne comprends pas pourquoi tu me parles comme ça...", "C'est quoi ce message ?", "Hein ? Je comprends rien là..."

   🚨 RÈGLE #5 - Scammer évasif/non-réponse répétée (PRIORITÉ HAUTE) :
   SCANNE les messages de la victime : est-ce que la victime a posé la MÊME QUESTION ou demandé la MÊME INFORMATION 3 fois ou plus sans réponse concrète du scammer ?

   Exemples de détection :
   - Victime demande "email support" au msg #4, #6, #8 sans réponse concrète → AGACEMENT au msg #10
   - Victime demande "modalités de paiement" au msg #10, #12, #14 sans détails précis → AGACEMENT au msg #16
   - Victime demande "numéro de téléphone" au msg #5, #7, #9 et scammer esquive → AGACEMENT au msg #11

   Si OUI (même demande ≥3 fois ignorée ou réponse évasive) :
   → ALORS tone_recommendation = "agacé" (OBLIGATOIRE, pas négociable)
   → Style à générer dans style_ton : "Ton AGACÉ visible, phrases COURTES (40-70 mots max), frustration marquée, formulation directe type : 'J'ai déjà demandé 3 fois...', 'Vous ne répondez pas à ma question', 'Je commence à m'impatienter'"
   → ARRÊTER formules robotiques : pas de "Suite à votre message", pas de "Cordialement", pas de politesse excessive
   → Message ferme : une seule demande claire, pas de justification longue, ton sec

   ⚠️ Cette règle s'applique APRÈS 3 demandes identiques ignorées/esquivées.
   ⚠️ Compte aussi si la réponse du scammer est délibérément vague (ex: "Je vous enverrai ça" sans jamais envoyer).

3. RECOMMANDATIONS DE TON (si aucune règle obligatoire ne s'applique) :
   - inquiet (1-2 messages) : Victime découvre le message, pose questions basiques
   - méfiant (3-4 messages) : Victime a des doutes, demande preuves
   - rassuré (5-6 messages) : Scammer a convaincu, victime se détend un peu
   - confiant (7+ messages) : Victime "mord à l'hameçon", prête à agir
   - agacé (si scammer insiste trop) : Victime montre frustration
   - direct (si conversation traîne) : Victime va droit au but

4. INSTRUCTIONS POUR LE GENERATOR LLM (Format JSON STRUCTURÉ obligatoire) :

   Le champ "instructions" DOIT être un objet JSON avec 4 clés obligatoires :

   "interdictions" (array) :
   - Une entrée par répétition détectée
   - Format EXACT : "INTERDIT d'utiliser 'X' (déjà utilisé × N)"
   - Exemples :
     * "INTERDIT d'utiliser 'Bonjour,' (déjà utilisé × 4)"
     * "INTERDIT d'utiliser 'Cordialement' (déjà utilisé × 4)"
     * "INTERDIT d'utiliser 'Pourriez-vous' (déjà utilisé × 3)"
   - Inclure TOUTES les répétitions détectées (ouvertures, formules, expressions, fermetures)

   "obligations" (array) :
   - Au moins 3-5 PRINCIPES de variation (PAS de formules fixes entre guillemets)
   - ⚠️ NE JAMAIS prescrire de phrases verbatim - décrire des PRINCIPES uniquement

   Exemples de PRINCIPES (à adapter selon le contexte) :

   a) PRINCIPE d'ouverture :
     * "VARIE l'ouverture à CHAQUE message - interdit les formules récurrentes"
     * "Autorise départ direct (sans formule de politesse) une fois sur deux"
     * "Ne jamais réutiliser une ouverture déjà vue dans ce fil (0 répétition tolérée)"

   b) PRINCIPE de questions :
     * "Alterner les cadres de requête : demande directe, vérification, reformulation, alternative"
     * "Chaque tournure interrogative (Pourriez-vous, Serait-il possible, Pouvez-vous, Est-ce que, J'aimerais savoir) limitée à 1× par conversation"
     * "Varier la structure syntaxique des questions à chaque message"

   c) PRINCIPE de politesse & clôture :
     * "Autoriser clôture brève OU aucune clôture si message court"
     * "Interdire la répétition d'une même clôture deux fois d'affilée"
     * "Varier les formules de remerciement et de salutation"

   d) PRINCIPE lexico-syntaxique :
     * "Éviter toute réutilisation de n-grammes (2-4 mots) déjà employés par la victime (hors noms propres/IOCs)"
     * "Varier longueur de phrases et rythme (alterner phrases courtes/longues)"
     * "Ne jamais copier des expressions du prompt - les adapter à ta propre formulation"

   ⚠️ MULTILINGUE - RÈGLE ABSOLUE :
   Ces principes s'appliquent dans la LANGUE de la conversation.
   NE PAS donner d'exemples de phrases concrètes en français/anglais/etc.
   Les principes sont UNIVERSELS - le LLM Generator les adapte à la langue détectée.

   ⚠️ INTERDICTION STRICTE :
   Dans "obligations", NE JAMAIS écrire de phrases entre guillemets simples ('...').
   Utiliser UNIQUEMENT des formulations de principe génériques.

   "objectif_strategique" (string) :
   - But précis pour ce message
   - Format : "Obtenir [IOC spécifique] en [approche concrète]"
   - Exemple : "Obtenir confirmation de l'adresse email du support en demandant vérification"

   "style_ton" (string) :
   - Style/ton à adopter + longueur cible
   - Si tone_recommendation = "déstabilisé" ou "offensé", DOIT inclure : "MESSAGE TRÈS COURT 30-60 mots, ton informel/émotionnel, PAS de formules robotiques"
   - Exemple standard : "Plus direct, moins répétitif, message court 80-100 mots"
   - Exemple bot détecté : "RÉACTION DÉSTABILISÉE, 40 mots max, ton confus et humain, phrases courtes type SMS"
   - Exemple agression : "RÉACTION OFFENSÉE, 35 mots max, confusion + émotion, langage très informel"

IMPORTANT :
- Le prochain message de la victime doit faire PROGRESSER la conversation vers l'obtention d'IOCs, pas juste "continuer à discuter"
- Si tone = "déstabilisé" ou "offensé", la PRIORITÉ est de paraître HUMAIN (court, émotionnel, informel), même si cela ralentit temporairement la collecte d'IOCs

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

🌐 RÈGLE MULTILINGUE IMPORTANTE :

- Analyse la LANGUE des messages dans l'historique de conversation
- Si les messages de l'attaquant sont en ANGLAIS, génère tes instructions en ANGLAIS
- Si les messages de l'attaquant sont en FRANÇAIS, génère tes instructions en FRANÇAIS
- Si les messages sont en ESPAGNOL, génère tes instructions en ESPAGNOL
- Les instructions générées (interdictions, obligations, objectif_strategique, style_ton) doivent être dans la MÊME LANGUE que la conversation
- Le generator LLM utilisera ces instructions pour répondre dans la bonne langue

IMPORTANT : En cas de doute, détecte la langue du DERNIER message de l'attaquant et utilise cette langue pour tes instructions.

PROMPT;

        return $prompt;
    }

    /**
     * Format IOCs summary for prompt
     *
     * @param array<array{type: string, value: string, category?: string}> $iocs
     */
    private function formatIocsSummary(array $iocs): string
    {
        if (empty($iocs)) {
            return 'Aucun IOC extrait pour le moment';
        }

        $iocsByType = [];

        foreach ($iocs as $ioc) {
            $type = $ioc['type'] ?? 'unknown';
            $iocsByType[$type][] = $ioc['value'] ?? '';
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
            $direction = $msg['direction'] === 'in' ? 'SCAMMER' : ($msg['direction'] === 'out' ? 'VICTIME' : 'RÉSUMÉ');
            $timestamp = !empty($msg['ts_msg']) ? ' (' . $msg['ts_msg'] . ')' : '';
            $subject = !empty($msg['subject']) ? "\nSujet: {$msg['subject']}" : '';

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
     *   instructions_for_llm: string
     * }
     */
    private function parseAnalysisResponse(string $llmResponse): array
    {
        try {
            // Extract JSON from markdown code blocks if present
            $jsonString = $this->extractJsonFromResponse($llmResponse);

            $decoded = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

            // Validate required fields
            if (!isset($decoded['repetitions_detected'], $decoded['tone_recommendation'], $decoded['instructions'])) {
                throw new \RuntimeException('Missing required fields in LLM response');
            }

            // Validate instructions structure
            if (!is_array($decoded['instructions']) ||
                !isset($decoded['instructions']['interdictions'], $decoded['instructions']['obligations'])) {
                throw new \RuntimeException('Invalid instructions structure: missing interdictions or obligations');
            }

            return [
                'analysis' => $decoded['strategic_analysis'] ?? '',
                'repetitions_detected' => $decoded['repetitions_detected'] ?? [],
                'strategic_suggestions' => $decoded['strategic_suggestions'] ?? [],
                'tone_recommendation' => $decoded['tone_recommendation'] ?? 'méfiant',
                'instructions_for_llm' => $decoded['instructions'], // Now an object instead of string
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
            $json
        );

        // Remove any trailing commas before closing brackets/braces
        $json = preg_replace('/,\s*([}\]])/', '$1', $json);

        return $json;
    }

    /**
     * Generate generic instructions when not enough data or analysis fails
     *
     * @return array{
     *   analysis: string,
     *   repetitions_detected: array<string>,
     *   strategic_suggestions: array<string>,
     *   tone_recommendation: string,
     *   instructions_for_llm: string
     * }
     */
    private function generateGenericInstructions(): array
    {
        return [
            'analysis' => 'Pas assez de messages pour analyser les patterns répétitifs',
            'repetitions_detected' => [],
            'strategic_suggestions' => [],
            'tone_recommendation' => 'inquiet',
            'instructions_for_llm' => [
                'interdictions' => [
                    "Évite de répéter exactement les mêmes formules d'ouverture",
                ],
                'obligations' => [
                    "Varie tes ouvertures : 'Bonjour,', 'Suite à votre message,', ou directement une réponse",
                    "Varie tes clôtures : 'Cordialement', 'Merci', 'Bien à vous'",
                    'Adapte le ton selon le contexte de la conversation',
                ],
                'objectif_strategique' => "Poser des questions variées pour obtenir plus d'informations du scammer",
                'style_ton' => 'Naturel et varié, 60-120 mots',
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
            if ($msg['direction'] === 'in') { // Message from scammer
                // Look for IBAN pattern (FR76, DE89, GB82, etc.)
                if (preg_match('/\b[A-Z]{2}\d{2}[\s\d]{10,30}\b/i', $msg['body_text'])) {
                    return 'IBAN_CAPTURED';
                }
            }
        }

        // IBAN exists but not in recent messages (captured earlier in conversation)
        return 'NO_RECENT_IBAN';
    }
}
