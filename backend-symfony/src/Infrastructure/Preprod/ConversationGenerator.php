<?php

declare(strict_types=1);

namespace App\Infrastructure\Preprod;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Application\Communication\IocHandler;
use App\Infrastructure\LLM\LLMServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Générateur de conversations scam réalistes pour environnement preprod
 *
 * Utilise des templates LLM + variations pour créer 10 000 conversations uniques
 * distribuées uniformément sur 27 personas × 13 scam types
 */
class ConversationGenerator
{
    private const MIN_MESSAGES = 2;
    private const MAX_MESSAGES = 50;  // Augmenté pour conversations réalistes longues
    private const MIN_TURNS = 2;      // Minimum 2 tours (1 scammer + 1 victim)
    private const MAX_TURNS = 15;     // Maximum 15 tours (30 messages) pour performance

    private ?string $authToken = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LLMServiceInterface $llm,
        private readonly IocGenerator $iocGenerator,
        private readonly IocHandler $iocHandler,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * Génère une conversation scam réaliste
     *
     * @param ScamType $scamType Type de scam
     * @param Persona $persona Persona à utiliser
     * @param Channel $channel Canal de communication
     * @param int $messageCount Nombre de messages (2-15)
     * @return Conversation
     */
    public function generateConversation(
        ScamType $scamType,
        Persona $persona,
        Channel $channel,
        int $messageCount
    ): Conversation {
        if ($messageCount < self::MIN_MESSAGES || $messageCount > self::MAX_MESSAGES) {
            throw new \InvalidArgumentException(
                sprintf('Message count must be between %d and %d', self::MIN_MESSAGES, self::MAX_MESSAGES)
            );
        }

        $this->logger->info('Generating conversation', [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
            'channel' => $channel->getCode(),
            'message_count' => $messageCount,
        ]);

        // Générer le contexte et les IOCs
        $this->logger->info('[DEBUG] Generating context...');
        $context = $this->generateContext($scamType, $persona, $channel);
        $this->logger->info('[DEBUG] Context generated, generating IOCs...');
        $iocs = $this->iocGenerator->generateIocsForScamType($scamType);
        $this->logger->info('[DEBUG] IOCs generated', [
            'iocs_count' => count($iocs),
            'iocs_content' => json_encode($iocs, JSON_UNESCAPED_UNICODE),
        ]);

        // Récupérer ou créer un MailAccount factice pour preprod
        $mailAccount = $this->getOrCreatePreprodMailAccount();

        $tsFirst = new \DateTimeImmutable(sprintf('-%d days -%d hours', rand(1, 90), rand(0, 23)));

        // Créer la conversation avec tous les paramètres requis
        $conversation = new Conversation(
            convId: $this->generateUuid(),
            primaryChannel: $channel,
            scamType: $scamType,
            account: $mailAccount,
            status: ConversationStatus::OPEN,
            scoreRisk: rand(50, 100),
            tsFirst: $tsFirst,
            tsLast: $tsFirst,
            stixId: 'preprod-' . uniqid(),
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );

        // Associer le persona
        $conversation->setPersona($persona);

        // Directions disponibles
        $dirIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $dirOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        if (!$dirIn || !$dirOut) {
            throw new \RuntimeException('Directions not found in database');
        }

        // Générer la conversation COMPLÈTE via 1 seul appel LLM (plus rapide!)
        $conversationMessages = $this->generateFullConversationDirect(
            scamType: $scamType,
            persona: $persona,
            context: $context,
            iocs: $iocs,
            messageCount: $messageCount
        );

        $this->logger->info('Full conversation generated', [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
            'message_count' => count($conversationMessages),
        ]);

        // Créer les entités Message
        $currentTime = $tsFirst;
        $lastMessageTime = $tsFirst;
        $turnsCount = 0;
        $messages = []; // Store messages for later IOC extraction

        for ($i = 0; $i < count($conversationMessages); $i++) {
            // Support des 2 formats: array avec role/content OU string simple
            if (is_array($conversationMessages[$i])) {
                $isScammerMessage = ($conversationMessages[$i]['role'] === 'scammer');
                $messageContent = $conversationMessages[$i]['content'];
            } else {
                $isScammerMessage = ($i % 2 === 0);
                $messageContent = $conversationMessages[$i];
            }
            $direction = $isScammerMessage ? $dirIn : $dirOut;

            $message = new Message(
                msgId: $this->generateUuid(),
                conversation: $conversation,
                channel: $channel,
                direction: $direction,
                langDetect: 'fr',
                subject: $i === 0 ? $this->generateSubject($scamType, $context) : null,
                bodyText: $messageContent,
                bodyHtml: null,
                headers: [],
                compositeHash: hash('sha256', $messageContent . $currentTime->format('c')),
                vectorId: null,
                replyTo: null,
                tsMsg: $currentTime,
                tsIngest: $currentTime,
                deletedAt: null
            );

            $this->em->persist($message);
            $messages[] = $message; // Store for IOC extraction

            // Compter les tours de parole (scammer + victim = 1 tour)
            if ($i % 2 === 1) {
                $turnsCount++;
            }

            // Incrémenter le temps entre messages (1h à 48h)
            $hoursGap = rand(1, 48);
            $lastMessageTime = $currentTime;
            $currentTime = $currentTime->modify(sprintf('+%d hours', $hoursGap));
        }

        // Calculer la durée d'engagement (temps entre premier et dernier message)
        $engagementDurationSec = $lastMessageTime->getTimestamp() - $tsFirst->getTimestamp();

        // Mettre à jour les métriques de la conversation
        $conversation->setEngagementDurationSec($engagementDurationSec);
        $conversation->setTurnsCount($turnsCount);

        // Persist conversation explicitly before flush (fixes Doctrine cascade error)
        $this->em->persist($conversation);

        $this->logger->error('[IOC-TRACE-1] AVANT FLUSH - Conversation va être persistée', [
            'message_count' => count($messages),
            'conversation_status' => $conversation->getStatus(),
        ]);

        // Flush messages to database BEFORE extracting IOCs (so they have IDs)
        $this->em->flush();

        $this->logger->error('[IOC-TRACE-2] APRÈS FLUSH - Messages persistés, début extraction IOCs', [
            'message_count' => count($messages),
        ]);

        // Extract IOCs from all messages using production-style extraction (hybrid regex+LLM)
        // ATTENTION: Mode 'hybrid' utilise le LLM pour chaque message, ceci peut être lent !
        $convId = $conversation->getConvId();
        $convIdStr = is_object($convId) && method_exists($convId, 'toString') ? $convId->toString() : (string) $convId;

        $this->logger->error('[IOC-DEBUG] ========== STARTING IOC EXTRACTION ==========', [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
            'message_count' => count($messages),
            'conversation_id' => $convIdStr,
        ]);

        $totalIocs = 0;
        $messageIndex = 0;

        // Extraction IOCs via HTTP API (comme workflow n8n - fonctionne en prod)
        // Cette approche permet de contourner les problèmes de DATABASE_URL et garantit
        // que les IOCs sont persistés dans la base preprod via l'endpoint HTTP

        foreach ($messages as $message) {
            $messageIndex++;
            $msgId = $message->getMsgId();
            $bodyText = $message->getBodyText();

            // getMsgId() peut retourner soit un UUID object soit une string
            $msgIdStr = is_object($msgId) && method_exists($msgId, 'toString') ? $msgId->toString() : (string) $msgId;

            $this->logger->info('[IOC-DEBUG] Processing message', [
                'message_index' => $messageIndex,
                'msg_id' => $msgIdStr,
                'body_length' => strlen($bodyText),
                'body_preview' => substr($bodyText, 0, 150) . '...',
            ]);

            try {
                $this->logger->info('[IOC-HTTP-API] AVANT extractIocsViaHttp()', [
                    'msg_id' => $msgIdStr,
                    'method' => 'llm',
                ]);

                // Utiliser l'approche HTTP API comme le workflow n8n (fonctionne en prod)
                $iocsCount = $this->extractIocsViaHttp($msgIdStr);

                $this->logger->info('[IOC-HTTP-API] APRÈS extractIocsViaHttp() - Résultat retourné', [
                    'msg_id' => $msgIdStr,
                    'iocs_count' => $iocsCount,
                ]);

                $totalIocs += $iocsCount;
            } catch (\Throwable $e) {
                $this->logger->error('[IOC-DEBUG] EXCEPTION in IOC extraction', [
                    'msg_id' => $msgIdStr,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                // Continue extraction for other messages
            }
        }

        $this->logger->info('[IOC-DEBUG] ========== IOC EXTRACTION COMPLETED ==========', [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
            'total_messages_processed' => $messageIndex,
            'total_iocs_extracted' => $totalIocs,
            'conversation_id' => $convIdStr,
        ]);

        // NE PAS clôturer ici ! Les conversations restent 'open' pour être fermées
        // via l'API /close qui calculera les rewards et mettra à jour les stats ε-greedy
        // (voir doc: docs/scambaiting-adaptatif/RAPPORT-VALIDATION-MULTI-CYCLES.md ligne 42)

        return $conversation;
    }

    /**
     * Génère une conversation COMPLÈTE via 1 seul appel LLM (RAPIDE)
     * Plus simple et plus rapide que l'approche itérative
     *
     * @return array<int, string> Liste de messages alternés (scammer, victim, scammer, victim...)
     */
    private function generateFullConversationDirect(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        int $messageCount
    ): array {
        $iocsStr = json_encode($iocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Tu es un générateur de conversations scam réalistes pour l'entraînement d'un système anti-scam.

**SCAM TYPE**: {$scamType->getLabel()}
**SCÉNARIO**: {$context['scenario']}

**PERSONA VICTIME**: {$persona->getPersonaLabel()}

**CONSIGNES**:
1. Génère EXACTEMENT $messageCount messages alternés (scammer commence, victime répond)
2. Le scammer utilise les techniques: {$context['emotional_triggers']}
3. La victime répond selon son profil: {$persona->getPersonaTone()}
4. Conversation RÉALISTE: erreurs de langue scammer, hésitations victime
5. **IMPORTANT IOCs**: Dans environ 40-60% des messages du scammer, inclure de façon NATURELLE des IOCs COMPLETS appropriés au contexte du scam.

**RÈGLES CRITIQUES pour les IOCs**:
- ❌ NE DIS JAMAIS: "Bitcoin", "IBAN", "notre site web" SANS donner l'adresse COMPLÈTE
- ✅ DIS TOUJOURS: L'adresse Bitcoin complète, l'IBAN complet, l'URL complète
- ✅ COPIE-COLLE EXACTEMENT les valeurs de la liste ci-dessous (ne modifie pas les chiffres/lettres)
- ✅ Intègre-les de façon NATURELLE dans le contexte du scam

**EXEMPLES CONCRETS D'INTÉGRATION NATURELLE**:
📧 Email: "Vous pouvez me joindre sur support@secure-verify.com pour finaliser"
🌐 URL: "Cliquez ici pour vérifier votre compte: https://secure-verify.com/login?token=abc123de456"
💳 IBAN: "Effectuez le virement vers: FR7630006000011234567890189 (Banque XYZ)"
📱 Téléphone: "Appelez-nous au +33 6 12 34 56 78 pour confirmer"
₿ Bitcoin: "Envoyez 0.5 BTC à l'adresse: 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"
💰 Ethereum: "Wallet ETH: 0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb"

**IOCs RÉALISTES GÉNÉRÉS - À UTILISER EXACTEMENT (copie-colle ces valeurs)**:
{$iocsStr}

**FORMAT** (JSON uniquement, sans texte avant/après):
["Message 1 scammer", "Message 1 victime", "Message 2 scammer", ...]
PROMPT;

        $response = $this->llm->complete($prompt, [
            'temperature' => 0.8,
            'max_tokens' => 3000,
        ]);

        // Parser la réponse JSON
        $cleaned = trim($response);

        // Extraire le JSON s'il y a du texte avant/après
        if (preg_match('/\[.*\]/s', $cleaned, $matches)) {
            $cleaned = $matches[0];
        }

        $messages = json_decode($cleaned, true);

        if (!is_array($messages) || empty($messages)) {
            $this->logger->error('Failed to parse LLM response', [
                'response' => $response,
                'cleaned' => $cleaned,
            ]);
            throw new \RuntimeException('LLM did not return valid JSON array');
        }

        // S'assurer qu'on a le bon nombre de messages (ajuster si nécessaire)
        if (count($messages) < $messageCount) {
            $this->logger->warning('LLM returned fewer messages than expected', [
                'expected' => $messageCount,
                'actual' => count($messages),
            ]);
        }

        // Limiter au nombre demandé
        $messages = array_slice($messages, 0, $messageCount);

        return $messages;
    }

    /**
     * Génère une conversation de manière ITÉRATIVE et RÉALISTE
     * Le scammer décide à chaque tour s'il continue ou abandonne
     *
     * @return array<int, array{role: string, content: string}> Messages alternés avec role et content
     */
    private function generateConversationIterative(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs
    ): array {
        $messages = [];
        $shouldContinue = true;
        $turnCount = 0;

        $this->logger->info('[ITERATIVE] Starting iterative conversation generation', [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
        ]);

        while ($shouldContinue && $turnCount < self::MAX_TURNS) {
            $this->logger->info('[ITERATIVE] Turn start', ['turn' => $turnCount]);

            // 1. SCAMMER envoie un message
            $this->logger->info('[ITERATIVE] Generating scammer message...');
            $scammerMessage = $this->generateScammerMessage(
                scamType: $scamType,
                persona: $persona,
                context: $context,
                iocs: $iocs,
                conversationHistory: $messages,
                turnNumber: $turnCount
            );

            $messages[] = ['role' => 'scammer', 'content' => $scammerMessage];
            $this->logger->info('[ITERATIVE] Scammer message added, generating victim response...');

            // 2. VICTIM (Scambuster persona) répond
            $victimMessage = $this->generateVictimMessage(
                persona: $persona,
                context: $context,
                conversationHistory: $messages,
                turnNumber: $turnCount
            );

            $messages[] = ['role' => 'victim', 'content' => $victimMessage];
            $this->logger->info('[ITERATIVE] Victim message added', ['turn' => $turnCount, 'total_messages' => count($messages)]);

            $turnCount++;

            // 3. Le SCAMMER décide: continuer ou abandonner ?
            // OPTIMISATION: Ne faire la décision que tous les 5 tours (au lieu de chaque tour)
            // Ça divise par ~2.5 le nombre d'appels LLM
            if ($turnCount % 5 === 0 || $turnCount >= 15) {
                $this->logger->info('[ITERATIVE] Checking if scammer continues...', ['turn' => $turnCount]);
                $shouldContinue = $this->scammerDecidesToContinue(
                    scamType: $scamType,
                    context: $context,
                    conversationHistory: $messages,
                    turnNumber: $turnCount
                );

                $this->logger->info('[ITERATIVE] Scammer decision received', ['should_continue' => $shouldContinue, 'turn' => $turnCount]);

                if (!$shouldContinue) {
                    $this->logger->info('Scammer decided to abandon conversation', [
                        'scam_type' => $scamType->getCode(),
                        'turn_count' => $turnCount,
                        'message_count' => count($messages),
                    ]);
                }
            } else {
                $this->logger->info('[ITERATIVE] Skipping decision check (continuing by default)', ['turn' => $turnCount]);
            }
        }

        $this->logger->info('[ITERATIVE] Conversation loop ended', ['final_turn_count' => $turnCount, 'total_messages' => count($messages), 'reason' => $shouldContinue ? 'max_turns_reached' : 'scammer_abandoned']);

        // Minimum 2 tours (4 messages)
        if ($turnCount < self::MIN_TURNS) {
            $this->logger->warning('Conversation too short, forcing minimum turns', [
                'actual_turns' => $turnCount,
                'min_turns' => self::MIN_TURNS,
            ]);
        }

        return $messages;
    }

    /**
     * Génère un message du SCAMMER
     */
    private function generateScammerMessage(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        array $conversationHistory,
        int $turnNumber
    ): string {
        $this->logger->info('[SCAMMER] Formatting history...');
        $historyStr = $this->formatConversationHistoryForPrompt($conversationHistory);
        $this->logger->info('[SCAMMER] History formatted, encoding IOCs...');
        $iocsStr = json_encode($iocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->logger->info('[SCAMMER] IOCs encoded, building prompt...');

        $prompt = <<<PROMPT
Tu es un SCAMMER expérimenté dans le type de scam: {$scamType->getLabel()}.

# CONTEXTE
Scénario: {$context['scenario']}
Hook psychologique: {$context['hook']}
Personnalité scammer: {$context['scammer_personality']}
Urgence: {$context['urgency_level']}
Tour actuel: {$turnNumber}

# HISTORIQUE CONVERSATION
$historyStr

# IOCs À INTÉGRER NATURELLEMENT
$iocsStr

# TON OBJECTIF
Tu veux obtenir: argent, informations sensibles, ou accès aux comptes de la victime.

# INSTRUCTIONS
1. Génère UNIQUEMENT le prochain message du scammer (30-200 mots)
2. Suis ta personnalité et ton scénario
3. Intègre les IOCs de manière naturelle (pas tous d'un coup!)
4. Adapte ta tactique selon les réponses de la victime
5. Utilise les leviers émotionnels: {$context['emotional_triggers']}
6. Langage 100% naturel, fluide, réaliste
7. AUCUNE métadonnée, UNIQUEMENT le contenu du message

GÉNÈRE MAINTENANT TON MESSAGE:
PROMPT;

        $this->logger->info('[SCAMMER] Calling LLM...', ['prompt_length' => strlen($prompt)]);
        $response = $this->llm->complete($prompt, [
            'temperature' => 0.8,
            'max_tokens' => 400,
        ]);
        $this->logger->info('[SCAMMER] LLM response received', ['response_length' => strlen($response)]);

        return trim($response);
    }

    /**
     * Génère un message de la VICTIM (Scambuster persona)
     */
    private function generateVictimMessage(
        Persona $persona,
        array $context,
        array $conversationHistory,
        int $turnNumber
    ): string {
        $historyStr = $this->formatConversationHistoryForPrompt($conversationHistory);

        $prompt = <<<PROMPT
Tu es une VICTIME d'un scam en cours. Tu joues le rôle du persona: {$persona->getPersonaLabel()}.

# PERSONA
Ton: {$persona->getPersonaTone()}
System Prompt:
{$persona->getSystemPrompt()}

# HISTORIQUE CONVERSATION
$historyStr

# TON RÔLE
Tu es un SCAMBAITER - ton objectif est de prolonger la conversation le plus longtemps possible pour faire perdre du temps au scammer.

# INSTRUCTIONS
1. Lis attentivement le dernier message du scammer
2. Réponds de manière COHÉRENTE avec ta personnalité
3. Montre de l'intérêt mais pose aussi des questions
4. Sois légèrement naïf/vulnérable mais pas trop
5. PROLONGE la conversation (ne révèle JAMAIS que tu es un scambaiter)
6. Message entre 20-150 mots
7. Langage naturel, AUCUNE métadonnée

GÉNÈRE MAINTENANT TA RÉPONSE:
PROMPT;

        $response = $this->llm->complete($prompt, [
            'temperature' => 0.7,
            'max_tokens' => 300,
        ]);

        return trim($response);
    }

    /**
     * Le SCAMMER décide s'il continue la conversation
     * Combine analyse LLM + probabilité aléatoire réaliste
     */
    private function scammerDecidesToContinue(
        ScamType $scamType,
        array $context,
        array $conversationHistory,
        int $turnNumber
    ): bool {
        // Limite de sécurité
        if ($turnNumber >= self::MAX_TURNS) {
            $this->logger->debug('Scammer stops: MAX_TURNS reached', ['turn' => $turnNumber]);
            return false;
        }

        // Toujours continuer les 3 premiers tours (démarrage)
        if ($turnNumber < 3) {
            return true;
        }

        // PROBABILITÉ D'ABANDON CROISSANTE avec le nombre de tours
        // Distribution réaliste basée sur la recherche:
        // - Tours 3-5: 20% abandon (conversations très courtes)
        // - Tours 6-10: 15% abandon (conversations courtes)
        // - Tours 11-15: 10% abandon (conversations moyennes)
        $abandonProbability = match (true) {
            $turnNumber <= 5 => 0.20,   // 20% abandon (conversations très courtes)
            $turnNumber <= 10 => 0.15,  // 15% abandon (conversations courtes)
            default => 0.10,            // 10% abandon (conversations moyennes/longues)
        };

        // Tirage aléatoire
        $randomValue = mt_rand(1, 100) / 100;
        if ($randomValue < $abandonProbability) {
            $this->logger->debug('Scammer stops: random abandon', [
                'turn' => $turnNumber,
                'probability' => $abandonProbability,
                'random' => $randomValue,
            ]);
            return false;
        }

        // Si pas d'abandon aléatoire, analyser les messages de la victime (plus léger)
        $recentVictimMessages = $this->getRecentVictimMessages($conversationHistory, 2);

        if (empty($recentVictimMessages)) {
            return false;
        }

        // Prompt SIMPLIFIÉ pour analyse rapide
        $prompt = <<<PROMPT
Tu es un scammer. Derniers messages de la victime:
$recentVictimMessages

La victime a-t-elle EXPLICITEMENT révélé qu'elle sait que c'est un scam ?
(mots-clés: "scam", "arnaque", "police", "fraude", "signaler")

Réponds: OUI ou NON
PROMPT;

        $response = $this->llm->complete($prompt, [
            'temperature' => 0.2,  // Très peu de variabilité
            'max_tokens' => 5,
        ]);

        $scamDetected = str_contains(strtoupper(trim($response)), 'OUI');

        $this->logger->debug('Scammer decision', [
            'turn' => $turnNumber,
            'scam_detected' => $scamDetected,
            'continue' => !$scamDetected,
        ]);

        // Abandonner seulement si la victime a révélé le scam
        return !$scamDetected;
    }

    /**
     * Récupère les N derniers messages de la victime
     */
    private function getRecentVictimMessages(array $conversationHistory, int $count): string
    {
        $victimMessages = array_filter($conversationHistory, fn($msg) => $msg['role'] === 'victim');
        $recent = array_slice($victimMessages, -$count);

        if (empty($recent)) {
            return "(Aucun message de la victime)";
        }

        return implode("\n---\n", array_map(fn($msg) => $msg['content'], $recent));
    }

    /**
     * Formate l'historique de conversation pour le prompt LLM
     * OPTIMISATION: Ne garde que les N derniers messages pour éviter de dépasser les rate limits OpenAI
     */
    private function formatConversationHistoryForPrompt(array $conversationHistory, int $maxMessages = 8): string
    {
        if (empty($conversationHistory)) {
            return "(Début de conversation)";
        }

        // Ne garder que les N derniers messages pour limiter la taille du prompt
        $recentHistory = array_slice($conversationHistory, -$maxMessages);

        $formatted = [];
        $startIndex = max(0, count($conversationHistory) - $maxMessages);
        foreach ($recentHistory as $idx => $msg) {
            $role = strtoupper($msg['role']);
            $formatted[] = sprintf("[Message %d - %s]\n%s", $startIndex + $idx + 1, $role, $msg['content']);
        }

        $prefix = count($conversationHistory) > $maxMessages
            ? sprintf("(... %d messages précédents omis ...)\n\n", count($conversationHistory) - $maxMessages)
            : "";

        return $prefix . implode("\n\n---\n\n", $formatted);
    }

    /**
     * Génère un contexte de scam réaliste avec template détaillé
     */
    private function generateContext(ScamType $scamType, Persona $persona, Channel $channel): array
    {
        $templates = ScamTemplates::getTemplates($scamType->getCode());
        $template = $templates[array_rand($templates)];

        return [
            'scam_type' => $scamType->getCode(),
            'persona' => $persona->getPersonaCode(),
            'channel' => $channel->getCode(),
            'scenario' => $template['scenario'],
            'hook' => $template['hook'],
            'progression' => $template['progression'],
            'scammer_personality' => $template['scammer_personality'],
            'urgency_level' => $template['urgency_level'],
            'emotional_triggers' => implode(', ', $template['emotional_triggers']),
            'variations' => $this->generateVariations(),
        ];
    }

    /**
     * Génère TOUTE la conversation en un seul appel LLM (beaucoup plus rapide!)
     *
     * @return array<string> Tableau de messages alternés (scammer, victim, scammer, ...)
     */
    private function generateFullConversation(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        int $messageCount
    ): array {
        $prompt = $this->buildFullConversationPrompt(
            scamType: $scamType,
            persona: $persona,
            context: $context,
            iocs: $iocs,
            messageCount: $messageCount
        );

        $response = $this->llm->complete($prompt, [
            'temperature' => 0.8,
            'max_tokens' => 2000, // Plus de tokens pour toute la conversation
        ]);

        // Parser la réponse pour extraire les messages (séparés par "---MESSAGE---")
        $messages = array_map('trim', explode('---MESSAGE---', trim($response)));
        $messages = array_filter($messages, fn($m) => !empty($m));

        // Nettoyer les en-têtes parasites "Message X (SCAMMER)" ou "Message X (VICTIM)"
        $messages = array_map(function($message) {
            // Supprimer les lignes comme "Message 1 (SCAMMER)" au début
            return preg_replace('/^Message\s+\d+\s*\([A-Z]+\)\s*\n?/i', '', trim($message));
        }, $messages);

        // S'assurer qu'on a le bon nombre de messages
        if (count($messages) < $messageCount) {
            // Compléter avec des messages génériques si manquants
            while (count($messages) < $messageCount) {
                $isScammer = (count($messages) % 2 === 0);
                $messages[] = $isScammer
                    ? "Merci pour votre réponse. Pouvez-vous me fournir plus de détails ?"
                    : "Oui, bien sûr. Que voulez-vous savoir exactement ?";
            }
        }

        return array_slice($messages, 0, $messageCount);
    }

    /**
     * Génère le contenu d'un message via LLM (DEPRECATED - utiliser generateFullConversation)
     */
    private function generateMessageContent(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        int $messageNumber,
        bool $isScammerMessage,
        string $conversationHistory
    ): string {
        $prompt = $this->buildMessagePrompt(
            scamType: $scamType,
            persona: $persona,
            context: $context,
            iocs: $iocs,
            messageNumber: $messageNumber,
            isScammerMessage: $isScammerMessage,
            conversationHistory: $conversationHistory
        );

        $response = $this->llm->complete($prompt, [
            'temperature' => 0.8, // Plus de variabilité
            'max_tokens' => 500,
        ]);

        return trim($response);
    }

    /**
     * Construit le prompt pour générer TOUTE la conversation en un seul appel
     */
    private function buildFullConversationPrompt(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        int $messageCount
    ): string {
        $iocsStr = json_encode($iocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $progression = isset($context['progression']) ? json_encode($context['progression'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'N/A';

        return <<<PROMPT
Tu dois générer une conversation scam COMPLÈTE et ULTRA-RÉALISTE en français.

# CONTEXTE
Type de scam: {$scamType->getCode()} - {$scamType->getLabel()}
Scénario: {$context['scenario']}
Hook psychologique: {$context['hook']}
Persona victime: {$persona->getPersonaLabel()}
Ton du persona: {$persona->getPersonaTone()}
Canal: {$context['channel']}
Nombre de messages: {$messageCount}

# PERSONNALITÉS
**SCAMMER**: {$context['scammer_personality']}
- Leviers émotionnels: {$context['emotional_triggers']}
- Niveau d'urgence: {$context['urgency_level']}

**VICTIM (Persona)**:
{$persona->getSystemPrompt()}

# PROGRESSION NARRATIVE
$progression

# IOCs À INTÉGRER NATURELLEMENT
$iocsStr

# INSTRUCTIONS CRITIQUES
1. Génère EXACTEMENT {$messageCount} messages alternés: SCAMMER, VICTIM, SCAMMER, VICTIM, etc.
2. Suis la progression narrative du template
3. Intègre les IOCs de manière naturelle (pas tous d'un coup!)
4. SCAMMER commence toujours (message 1)
5. Langage 100% naturel, fluide, réaliste
6. Varie la longueur des messages (30-200 mots)
7. AUCUNE métadonnée, UNIQUEMENT le contenu brut
8. Sépare chaque message par "---MESSAGE---" sur une ligne seule

# FORMAT DE SORTIE
Message 1 (SCAMMER)
---MESSAGE---
Message 2 (VICTIM)
---MESSAGE---
Message 3 (SCAMMER)
... etc.

GÉNÈRE MAINTENANT LA CONVERSATION:
PROMPT;
    }

    /**
     * Construit le prompt LLM pour générer un message ULTRA-RÉALISTE (DEPRECATED)
     */
    private function buildMessagePrompt(
        ScamType $scamType,
        Persona $persona,
        array $context,
        array $iocs,
        int $messageNumber,
        bool $isScammerMessage,
        string $conversationHistory
    ): string {
        $role = $isScammerMessage ? 'SCAMMER' : 'VICTIM';
        $iocsStr = json_encode($iocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Déterminer l'étape de progression narrative
        $progressionStep = $this->getProgressionStep($context, $messageNumber, $isScammerMessage);

        $scammerInstructions = <<<SCAMMER
# TON RÔLE: SCAMMER
Personnalité: {$context['scammer_personality']}

## Étape narrative actuelle:
$progressionStep

## Tactiques psychologiques à utiliser:
- Leviers émotionnels: {$context['emotional_triggers']}
- Niveau d'urgence: {$context['urgency_level']}

## IOCs à intégrer naturellement:
$iocsStr

## Comment jouer ce message:
1. Suis EXACTEMENT l'étape de progression narrative
2. Intègre les IOCs de manière naturelle (pas tous à la fois!)
3. Utilise les tactiques psychologiques définies
4. Reste cohérent avec ta personnalité de scammer
5. Crée l'urgence selon le niveau spécifié
6. Langage fluide, professionnel si nécessaire, familier si pertinent
7. Fautes d'orthographe UNIQUEMENT si cohérent avec personnage (ex: scammer étranger)

SCAMMER;

        $victimInstructions = <<<VICTIM
# TON RÔLE: VICTIM
Persona: {$persona->getPersonaLabel()}
Ton: {$persona->getPersonaTone()}
System Prompt du persona:
{$persona->getSystemPrompt()}

## Comment réagir à ce message:
1. Lis attentivement le dernier message du scammer
2. Réagis de manière COHÉRENTE avec ta personnalité
3. Montre les émotions attendues (inquiétude, doute, curiosité, etc.)
4. Ne sois PAS trop naïf - pose des questions légitimes
5. Mais reste vulnérable aux tactiques psychologiques du scammer
6. JAMAIS révéler que tu es un système/test/leurre
7. Langage naturel, phrases courtes ou longues selon ton persona

VICTIM;

        $instructions = $isScammerMessage ? $scammerInstructions : $victimInstructions;

        return <<<PROMPT
Tu dois générer un message ULTRA-RÉALISTE pour une conversation de scam en cours.

# CONTEXTE COMPLET
Type de scam: {$scamType->getCode()} - {$scamType->getLabel()}
Scénario: {$context['scenario']}
Hook psychologique: {$context['hook']}
Canal de communication: {$context['channel']}
Message numéro: {$messageNumber}

# HISTORIQUE CONVERSATION
$conversationHistory

---

$instructions

# CONTRAINTES CRITIQUES
- Message entre 30 et 250 mots (varie selon contexte)
- Langage 100% naturel, fluide, réaliste
- AUCUNE signature système, métadonnée, ou mention d'IA
- UNIQUEMENT le contenu brut du message
- Pas de formules trop polies si incohérent avec personnage
- Utilise emojis UNIQUEMENT si cohérent avec le canal/personnage
- Si email: peut avoir objet/corps, si SMS: court et direct

Génère MAINTENANT le message (contenu brut uniquement):
PROMPT;
    }

    /**
     * Détermine l'étape de progression narrative selon le numéro de message
     */
    private function getProgressionStep(array $context, int $messageNumber, bool $isScammer): string
    {
        if (!isset($context['progression'])) {
            return "Continue la conversation de manière naturelle";
        }

        $progression = $context['progression'];
        $stepIndex = (int) floor(($messageNumber - 1) / 2);

        if ($isScammer) {
            $stepKey = 'scammer_' . ceil($messageNumber / 2);
        } else {
            $stepKey = 'victim_' . ceil($messageNumber / 2);
        }

        return $progression[$stepKey] ?? "Continue la conversation de manière cohérente";
    }

    /**
     * Construit l'historique de la conversation à partir du tableau local
     */
    private function buildConversationHistory(array $messages): string
    {
        if (empty($messages)) {
            return "(Début de conversation)";
        }

        $history = [];
        foreach ($messages as $idx => $msg) {
            $role = $msg['direction']->getCode() === 'in' ? 'SCAMMER' : 'VICTIM';
            $history[] = sprintf("[Message %d - %s]\n%s\n", $idx + 1, $role, $msg['content']);
        }

        return implode("\n---\n", $history);
    }

    /**
     * Génère un sujet d'email réaliste
     */
    private function generateSubject(ScamType $scamType, array $context): string
    {
        $subjects = $this->getSubjectTemplates($scamType);
        $template = $subjects[array_rand($subjects)];

        return $this->applyVariations($template, $context['variations']);
    }

    /**
     * Génère des variations pour éviter les répétitions
     */
    private function generateVariations(): array
    {
        return [
            'company' => $this->randomChoice(['Microsoft', 'Apple', 'Amazon', 'PayPal', 'Netflix', 'Google']),
            'amount' => sprintf('%.2f', rand(100, 10000) / 10),
            'currency' => $this->randomChoice(['EUR', 'USD', 'GBP']),
            'deadline_days' => rand(1, 7),
            'reference' => strtoupper(substr(md5(uniqid()), 0, 8)),
        ];
    }

    /**
     * Applique les variations à un template
     */
    private function applyVariations(string $template, array $variations): string
    {
        foreach ($variations as $key => $value) {
            $template = str_replace("{{" . $key . "}}", (string)$value, $template);
        }
        return $template;
    }

    /**
     * Retourne les templates de contexte par type de scam
     */
    private function getContextTemplates(ScamType $scamType): array
    {
        // Simplified templates - real implementation would have 100+ templates
        $templates = [
            'PHISH_CREDENTIALS' => [
                [
                    'scenario' => 'Phishing Microsoft Office 365 - compte suspendu',
                    'hook' => 'Votre compte sera désactivé dans 24h si vous ne vérifiez pas vos informations',
                    'urgency_level' => 'high',
                ],
                [
                    'scenario' => 'Phishing bancaire - transaction suspecte',
                    'hook' => 'Activité inhabituelle détectée sur votre compte',
                    'urgency_level' => 'critical',
                ],
            ],
            'ROMANCE_SCAM' => [
                [
                    'scenario' => 'Arnaque sentimentale - personne en détresse à l\'étranger',
                    'hook' => 'Bloqué à l\'étranger, besoin d\'aide urgente',
                    'urgency_level' => 'medium',
                ],
            ],
            'TECH_SUPPORT' => [
                [
                    'scenario' => 'Faux support Microsoft - ordinateur infecté',
                    'hook' => 'Votre PC est infecté par un virus dangereux',
                    'urgency_level' => 'critical',
                ],
            ],
        ];

        $code = $scamType->getCode();
        return $templates[$code] ?? [
            [
                'scenario' => 'Scam générique',
                'hook' => 'Action requise',
                'urgency_level' => 'medium',
            ],
        ];
    }

    /**
     * Retourne les templates de sujets par type de scam
     */
    private function getSubjectTemplates(ScamType $scamType): array
    {
        $templates = [
            'PHISH_CREDENTIALS' => [
                'Action requise: Vérifiez votre compte {{company}}',
                'Votre compte {{company}} expire dans {{deadline_days}} jours',
                '⚠️ Activité suspecte détectée',
            ],
            'ROMANCE_SCAM' => [
                'Besoin de ton aide...',
                'Urgence - situation délicate',
            ],
            'TECH_SUPPORT' => [
                'ALERTE: Virus détecté sur votre ordinateur',
                '🔒 Sécurité {{company}} - Action immédiate requise',
            ],
        ];

        $code = $scamType->getCode();
        return $templates[$code] ?? ['Action requise'];
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function randomChoice(array $options): mixed
    {
        return $options[array_rand($options)];
    }

    /**
     * Récupère ou crée un MailAccount factice pour preprod
     */
    private function getOrCreatePreprodMailAccount(): MailAccount
    {
        // Essayer de récupérer un compte existant
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        if ($account) {
            return $account;
        }

        // Créer un compte factice si aucun n'existe (basé sur structure réelle dev)
        $account = new MailAccount(
            accountId: $this->generateUuid(),
            ownerId: $this->generateUuid(),
            protocol: 'IMAP',
            endpoint: 'preprod.imap.scambuster.local',
            loginHash: hash('sha256', 'preprod-login-hash'),
            oauthScopes: [],
            isActive: true,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            port: 993,
            secure: true
        );

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    /**
     * Récupère un token JWT d'authentification pour l'API preprod
     */
    private function getAuthToken(): string
    {
        if ($this->authToken !== null) {
            return $this->authToken;
        }

        try {
            // Communication inter-containers : utiliser nom container + port interne 8080
            $response = $this->httpClient->request('POST', 'http://scambuster-backend-preprod:8080/api/v1/auth/login', [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'email' => 'admin@example.com',
                    'password' => 'Un1que$trongPassword2024',
                ],
            ]);

            $data = $response->toArray();
            $this->authToken = $data['access_token'] ?? throw new \RuntimeException('No access_token in auth response');

            return $this->authToken;
        } catch (\Throwable $e) {
            $this->logger->error('[IOC-HTTP-AUTH] Failed to retrieve JWT token', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to authenticate with API: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extrait les IOCs d'un message via l'API HTTP (comme n8n workflow)
     *
     * @param string $msgId UUID du message
     * @return int Nombre d'IOCs détectés
     */
    private function extractIocsViaHttp(string $msgId): int
    {
        try {
            $token = $this->getAuthToken();

            // Communication inter-containers : utiliser nom container + port interne 8080
            $response = $this->httpClient->request(
                'POST',
                "http://scambuster-backend-preprod:8080/api/v1/communication/message/{$msgId}/extract-iocs",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'json' => [
                        'method' => 'llm',  // Utiliser méthode LLM comme en production
                        'types' => [],      // Extraire tous les types d'IOCs
                        'persist' => true,  // Persister en base preprod
                    ],
                    'timeout' => 30,  // 30s timeout pour l'appel LLM
                ]
            );

            $data = $response->toArray();
            $iocs = $data['iocs'] ?? [];

            $this->logger->info('[IOC-HTTP-API] IOCs extracted successfully', [
                'msg_id' => $msgId,
                'iocs_count' => count($iocs),
                'http_status' => $response->getStatusCode(),
            ]);

            return count($iocs);
        } catch (\Throwable $e) {
            $this->logger->error('[IOC-HTTP-API] Failed to extract IOCs via HTTP', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;  // Retourner 0 en cas d'erreur
        }
    }
}
