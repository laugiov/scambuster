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
    /** @phpstan-ignore-next-line Reserved for future use */
    private const MIN_TURNS = 2;      // Minimum 2 tours (1 scammer + 1 victim)
    /** @phpstan-ignore-next-line Reserved for future use */
    private const MAX_TURNS = 15;     // Maximum 15 tours (30 messages) pour performance

    private ?string $authToken = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LLMServiceInterface $llm,
        private readonly IocGenerator $iocGenerator,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * Génère une conversation scam réaliste
     *
     * @param ScamType $scamType     Type de scam
     * @param Persona  $persona      Persona à utiliser
     * @param Channel  $channel      Canal de communication
     * @param int      $messageCount Nombre de messages (2-15)
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

        $tsFirst = new \DateTimeImmutable(sprintf('-%d days -%d hours', random_int(1, 90), random_int(0, 23)));

        // Créer la conversation avec tous les paramètres requis
        $conversation = new Conversation(
            convId: $this->generateUuid(),
            primaryChannel: $channel,
            scamType: $scamType,
            account: $mailAccount,
            status: ConversationStatus::OPEN,
            scoreRisk: random_int(50, 100),
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
        $messages = [];
        // Store messages for later IOC extraction
        $counter = count($conversationMessages); // Store messages for later IOC extraction

        for ($i = 0; $i < $counter; $i++) {
            // Support des 2 formats: array avec role/content OU string simple
            /** @var string|array{role: string, content: string} $msgItem */
            $msgItem = $conversationMessages[$i];

            if (is_array($msgItem)) {
                $isScammerMessage = ($msgItem['role'] === 'scammer');
                $messageContent = $msgItem['content'];
            } else {
                $isScammerMessage = ($i % 2 === 0);
                $messageContent = $msgItem;
            }
            $direction = $isScammerMessage ? $dirIn : $dirOut;

            $message = new Message(
                msgId: $this->generateUuid(),
                conversation: $conversation,
                channel: $channel,
                direction: $direction,
                langDetect: 'en',
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
            $hoursGap = random_int(1, 48);
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
        $convIdStr = $convId;

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

            $msgIdStr = $msgId;

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
     * @param array<string, mixed> $context
     * @param array<string, mixed> $iocs
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
        /** @var string $ctxScenario */
        $ctxScenario = $context['scenario'] ?? '';
        /** @var string $ctxTriggers */
        $ctxTriggers = $context['emotional_triggers'] ?? '';

        $prompt = <<<PROMPT
You are a realistic scam conversation generator for training an anti-scam detection system.

**SCAM TYPE**: {$scamType->getLabel()}
**SCENARIO**: {$ctxScenario}

**VICTIM PERSONA**: {$persona->getPersonaLabel()}

**INSTRUCTIONS**:
1. Generate EXACTLY $messageCount alternating messages (scammer starts, victim responds)
2. The scammer uses these techniques: {$ctxTriggers}
3. The victim responds according to their profile: {$persona->getPersonaTone()}
4. REALISTIC conversation: scammer may have occasional grammar mistakes, victim shows hesitation
5. **IMPORTANT IOCs**: In approximately 40-60% of the scammer's messages, naturally include COMPLETE IOCs appropriate to the scam context.
6. ALL messages MUST be in ENGLISH.

**CRITICAL RULES for IOCs**:
- NEVER say: "Bitcoin", "IBAN", "our website" WITHOUT giving the COMPLETE address
- ALWAYS include: the full Bitcoin address, full IBAN, full URL
- COPY-PASTE EXACTLY the values from the list below (do not modify digits/letters)
- Integrate them NATURALLY into the scam context

**CONCRETE EXAMPLES OF NATURAL IOC INTEGRATION**:
Email: "You can reach me at support@secure-verify.com to finalize"
URL: "Click here to verify your account: https://secure-verify.com/login?token=abc123de456"
IBAN: "Wire the payment to: FR7630006000011234567890189 (Bank XYZ)"
Phone: "Call us at +1-800-555-0199 to confirm"
Bitcoin: "Send 0.5 BTC to address: 1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa"
Ethereum: "ETH wallet: 0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb"

**REALISTIC IOCs TO USE EXACTLY (copy-paste these values)**:
{$iocsStr}

**FORMAT** (JSON only, no text before/after):
["Message 1 scammer", "Message 1 victim", "Message 2 scammer", ...]
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

        if (!is_array($messages) || $messages === []) {
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
        /** @var array<int, string> $messages */
        $messages = array_slice($messages, 0, $messageCount);

        return $messages;
    }

    /**
     * Génère un contexte de scam réaliste avec template détaillé
     *
     * @return array<string, mixed>
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
            'emotional_triggers' => implode(', ', (array) $template['emotional_triggers']),
            'variations' => $this->generateVariations(),
        ];
    }

    /**
     * Génère un sujet d'email réaliste
     *
     * @param array<string, mixed> $context
     */
    private function generateSubject(ScamType $scamType, array $context): string
    {
        $subjects = $this->getSubjectTemplates($scamType);
        $template = $subjects[array_rand($subjects)];

        /** @var array<string, int|string> $variations */
        $variations = $context['variations'] ?? [];

        return $this->applyVariations($template, $variations);
    }

    /**
     * Génère des variations pour éviter les répétitions
     *
     * @return array<string, mixed>
     */
    private function generateVariations(): array
    {
        return [
            'company' => $this->randomChoice(['Microsoft', 'Apple', 'Amazon', 'PayPal', 'Netflix', 'Google']),
            'amount' => sprintf('%.2f', random_int(100, 10000) / 10),
            'currency' => $this->randomChoice(['EUR', 'USD', 'GBP']),
            'deadline_days' => random_int(1, 7),
            'reference' => strtoupper(substr(md5(uniqid()), 0, 8)),
        ];
    }

    /**
     * Applique les variations à un template
     *
     * @param array<string, string|int> $variations
     */
    private function applyVariations(string $template, array $variations): string
    {
        foreach ($variations as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        }

        return $template;
    }

    /**
     * Retourne les templates de sujets par type de scam
     *
     * @return array<int, string>
     */
    private function getSubjectTemplates(ScamType $scamType): array
    {
        $templates = [
            'PHISH_CREDENTIALS' => [
                'Action Required: Verify your {{company}} account',
                'Your {{company}} account expires in {{deadline_days}} days',
                'Security Alert: Suspicious activity detected',
            ],
            'BEC_CEO' => [
                'Urgent - Confidential',
                'Re: Wire transfer - time sensitive',
                'IMPORTANT: Action needed before EOD',
            ],
            'BANK_IMPERSONATION' => [
                'Security Alert: Unusual activity on your account',
                'Fraud Prevention Notice - Immediate action required',
                'Your card has been flagged - verify now',
            ],
            'GOV_IMPERSONATION' => [
                'IRS Notice: Tax Refund Pending',
                'Social Security Administration - Action Required',
                'DMV: License renewal notice',
            ],
            'ROMANCE' => [
                'I need your help...',
                'Please read this, it\'s urgent',
                'Missing you, but something happened...',
            ],
            'TECH_SUPPORT' => [
                'ALERT: Virus detected on your computer',
                'Security Warning: {{company}} - Immediate action required',
                'Critical System Alert - Do not ignore',
            ],
            'ADVANCE_FEE_419' => [
                'Inheritance Notification - Confidential',
                'CONGRATULATIONS! You have been selected',
                'Urgent assistance needed - mutual benefit',
            ],
            'INVESTMENT_SCAM' => [
                'Exclusive investment opportunity - limited spots',
                'Your portfolio could grow 15% monthly',
                'Private invitation: Join our trading group',
            ],
            'DELIVERY_SCAM' => [
                'Your package is being held - action required',
                'Delivery failed - update your address',
                'FedEx: Customs fee pending for your shipment',
            ],
            'INVOICE_FRAUD' => [
                'Invoice #INV-{{reference}} - Payment due',
                'Updated banking details - please note',
                'FINAL NOTICE: Overdue payment',
            ],
        ];

        $code = $scamType->getCode();

        return $templates[$code] ?? ['Action required'];
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

    /**
     * @template T
     *
     * @param array<int|string, T> $options
     *
     * @return T
     */
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

        if ($account !== null) {
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
     *
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
