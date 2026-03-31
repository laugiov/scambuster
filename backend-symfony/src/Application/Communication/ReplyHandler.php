<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\LLM\LanguageDetector;
use App\Application\LLM\ReplyOrchestrator;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class ReplyHandler
{
    private const MIN_HOURS_BETWEEN_REPLIES = 6;
    /** @phpstan-ignore classConstant.unused */
    private const MAX_REPLIES_PER_DAY = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private MessageHandler $messageHandler,
        private ReplyOrchestrator $replyOrchestrator,
        private LoggerInterface $logger,
        private PersonaManager $personaManager, // @phpstan-ignore property.onlyWritten
        private \App\Application\Scambaiting\PersonaOptimizer $personaOptimizer,
        private ?ScamClassificationHandler $scamClassificationHandler = null,
        private ?IocHandler $iocHandler = null,
        private ?ConversationHistoryService $conversationHistoryService = null,
        private ?RateLimiterFactory $repliesPerConversationLimiter = null,
        private ?RateLimiterFactory $llmCallsPerHourLimiter = null,
        private ?RateLimiterFactory $activeConversationsPerDayLimiter = null,
        private ?AuditLogger $auditLogger = null,
        private ?LanguageDetector $languageDetector = null,
        private ?MailerInterface $mailer = null,
    ) {
    }

    /**
     * Get a message by ID (delegated to MessageHandler)
     */
    public function getMessage(string $msgId): ?Message
    {
        return $this->messageHandler->getMessage($msgId);
    }

    /**
     * Get conversation context for LLM generation
     */
    /** @return array<string, mixed>|null */
    public function getConversationContext(string $convId, int $messageLimit = 5): ?array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation || $conversation->getDeletedAt() !== null) {
            return null;
        }

        // Automatic classification if scam_type is 'unknown' and classifier is available
        // This MUST happen BEFORE building the context to avoid race conditions
        if ($conversation->getScamType()->getCode() === 'unknown' && $this->scamClassificationHandler !== null) {
            try {
                $classificationResult = $this->scamClassificationHandler->classifyConversation($convId);

                $this->logger->info('Automatic classification completed in getConversationContext', [
                    'conv_id' => $convId,
                    'scam_type' => $classificationResult->scamTypeCode,
                    'confidence' => $classificationResult->confidence,
                    'is_new_type' => $classificationResult->isNewType,
                    'is_new_persona' => $classificationResult->isNewPersona,
                ]);

                // Refresh conversation entity to get updated scam_type
                $this->em->refresh($conversation);

            } catch (\Exception $e) {
                // Log error but continue with 'unknown' - graceful degradation
                $this->logger->warning('Automatic classification failed in getConversationContext', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Get last N messages ordered by ts_msg desc
        /** @var Message[] $messages */
        $messages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :conv')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conv', $conversation)
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults($messageLimit)
            ->getQuery()
            ->getResult();

        // Reverse to get chronological order
        $messages = array_reverse($messages);

        /** @var array<int, array<string, mixed>> $lastMessages */
        $lastMessages = array_map(function (Message $msg) {
            return [
                'msg_id' => $msg->getMsgId(),
                'direction' => $msg->getDirection()->getCode(),
                'subject' => $msg->getSubject(),
                'body_text' => $msg->getBodyText(),
                'headers' => [
                    'from' => $msg->getHeaders()['from'] ?? null,
                    'message_id' => $msg->getHeaders()['message_id'] ?? null,
                    'references' => $msg->getHeaders()['references'] ?? null,
                    'in_reply_to' => $msg->getHeaders()['in_reply_to'] ?? null,
                ],
                'ts_msg' => $msg->getTsMsg()->format(DATE_ATOM),
            ];
        }, $messages);

        // Get persona from conversation (already assigned) or assign randomly if first time
        $scamType = $conversation->getScamType();
        $persona = $conversation->getPersona();

        // If no persona assigned yet, use ε-greedy optimizer to select best persona
        if ($persona === null) {
            $personaCode = $this->personaOptimizer->selectPersona($scamType->getCode());

            if ($personaCode !== null) {
                $persona = $this->em->getRepository(\App\Domain\Communication\Persona::class)
                    ->findOneBy(['personaCode' => $personaCode, 'isActive' => true]);

                if ($persona !== null) {
                    $conversation->setPersona($persona);
                    $this->em->flush();
                    $this->logger->info('[ReplyHandler] Persona assigned to conversation (ε-greedy optimized)', [
                        'conv_id' => $convId,
                        'persona_code' => $persona->getPersonaCode(),
                        'scam_type' => $scamType->getCode(),
                    ]);
                } else {
                    $this->logger->error('[ReplyHandler] Persona not found after optimization', [
                        'conv_id' => $convId,
                        'persona_code' => $personaCode,
                        'scam_type' => $scamType->getCode(),
                    ]);
                }
            } else {
                $this->logger->warning('[ReplyHandler] No persona selected by optimizer', [
                    'conv_id' => $convId,
                    'scam_type' => $scamType->getCode(),
                ]);
            }
        }

        $personaCode = $persona ? $persona->getPersonaCode() : 'generic_user';

        // Get extracted IOCs for this conversation (for ConversationAnalyzer)
        $extractedIocs = [];

        if ($this->iocHandler !== null) {
            try {
                $iocs = $this->iocHandler->getConversationIocs($convId);
                $extractedIocs = array_map(function ($ioc) {
                    return [
                        'type' => $ioc['type'] ?? 'unknown',
                        'value' => $ioc['value'] ?? '',
                        'category' => $ioc['category'] ?? null,
                    ];
                }, $iocs);
            } catch (\Throwable $e) {
                $this->logger->debug('[ReplyHandler] Failed to fetch IOCs', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Get sender history summary if service is available and there are inbound messages
        $senderHistorySummary = null;

        $this->logger->debug('[ReplyHandler] Checking for conversation history service', [
            'conv_id' => $convId,
            'service_available' => $this->conversationHistoryService !== null,
            'has_messages' => !empty($lastMessages),
        ]);

        if ($this->conversationHistoryService !== null && !empty($lastMessages)) {
            // Find the first inbound message to get sender email
            $firstInboundMsg = null;

            foreach ($lastMessages as $msg) {
                /** @var array<string, mixed> $msgHeaders */
                $msgHeaders = $msg['headers'] ?? [];

                if ($msg['direction'] === 'in' && isset($msgHeaders['from'])) {
                    $firstInboundMsg = $msg;

                    break;
                }
            }

            if ($firstInboundMsg !== null) {
                /** @var array<string, mixed> $firstInboundHeaders */
                $firstInboundHeaders = $firstInboundMsg['headers'] ?? [];
                /** @var string $senderEmail */
                $senderEmail = $firstInboundHeaders['from'] ?? '';

                try {
                    $senderHistorySummary = $this->conversationHistoryService->getSenderHistorySummary(
                        $convId,
                        $senderEmail
                    );

                    if ($senderHistorySummary !== null) {
                        $this->logger->info('[ReplyHandler] Sender history summary generated', [
                            'conv_id' => $convId,
                            'sender_email' => $senderEmail,
                            'summary_length' => strlen($senderHistorySummary),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Graceful degradation: log error but continue without summary
                    $this->logger->warning('[ReplyHandler] Failed to generate sender history summary', [
                        'conv_id' => $convId,
                        'sender_email' => $senderEmail,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'conv_id' => $conversation->getConvId(),
            'status' => $conversation->getStatus()->value,
            'scam_type' => [
                'code' => $scamType->getCode(),
                'label' => $scamType->getLabel(),
            ],
            'persona' => $personaCode,
            'cadence' => [
                'min_hours_between_replies' => self::MIN_HOURS_BETWEEN_REPLIES,
            ],
            'last_messages' => $lastMessages,
            'extracted_iocs' => $extractedIocs,
            'sender_history_summary' => $senderHistorySummary,
        ];
    }

    /**
     * Generate reply draft (placeholder - will be implemented with LLM)
     */
    /** @return array<string, mixed>|null */
    public function generateReply(string $convId, string $lastMsgId, bool $force = false, string $reason = 'manual'): ?array
    {
        // getConversationContext() handles automatic classification if needed
        $context = $this->getConversationContext($convId);

        if (!$context) {
            return null;
        }

        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($this->isKillSwitchActive()) {
            throw new \RuntimeException('Kill switch is active - all automated replies are halted');
        }

        if ($conversation->getStatus()->value !== 'open') {
            throw new \RuntimeException('Cannot generate reply for closed conversation');
        }

        // Check cadence
        if (!$force && !$this->checkCadence($convId)) {
            throw new \RuntimeException('Cadence limit not met');
        }

        // Check Redis-backed rate limits
        if (!$force) {
            $rateLimitResult = $this->checkRateLimits($convId);

            if ($rateLimitResult !== null) {
                throw new \RuntimeException('Rate limit exceeded: ' . $rateLimitResult);
            }
        }

        $parentMessage = $this->messageHandler->getMessage($lastMsgId);

        if (!$parentMessage) {
            return null;
        }

        // Detect language from last inbound message
        $detectedLanguage = $this->detectLanguageFromContext($context);
        $context['detected_language'] = $detectedLanguage;

        // Generate reply using LLM Orchestrator
        $personaCode = $context['persona'];
        $llmResult = $this->replyOrchestrator->generate($context, $personaCode);

        // Check if reply was approved
        if (!$llmResult['approved']) {
            $this->logger->warning('LLM reply rejected', [
                'conversation_id' => $convId,
                'policy_flags' => $llmResult['policy_flags'],
                'validation_reasons' => $llmResult['validation_reasons'],
            ]);

            throw new \RuntimeException(
                'Reply rejected by LLM validation: ' . implode(', ', array_merge(
                    $llmResult['policy_flags'],
                    $llmResult['validation_reasons']
                ))
            );
        }

        // Log if fallback was used
        if (!empty($llmResult['fallback_used'])) {
            $this->logger->warning('LLM generation failed - using fallback placeholder', [
                'conversation_id' => $convId,
                'attempts' => $llmResult['attempts'],
                'policy_flags' => $llmResult['policy_flags'],
                'validation_reasons' => $llmResult['validation_reasons'],
            ]);
        }

        $newReplyContent = $llmResult['text'];

        // Simple text and HTML versions (no conversation history needed - Gmail handles threading)
        $replyText = $newReplyContent;
        $replyHtml = '<div>' . nl2br(htmlspecialchars($newReplyContent, ENT_QUOTES, 'UTF-8')) . '</div>';

        // Determine recipient
        $to = $parentMessage->getHeaders()['reply_to'] ?? $parentMessage->getHeaders()['from'] ?? null;

        if (!$to) {
            throw new \RuntimeException('Cannot determine reply recipient');
        }

        // Build subject
        $subject = $parentMessage->getSubject() ?? '';

        if (!preg_match('/^re:/i', $subject)) {
            $subject = 'Re: ' . $subject;
        }

        // Get parent Gmail message ID (provider_msg_id) for n8n Reply operation
        $parentGmailMsgId = $parentMessage->getProviderMsgId()
            ?? $parentMessage->getHeaders()['provider_msg_id']
            ?? null;

        // Create outgoing message
        $channelEmail = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        if (!$channelEmail || !$directionOut) {
            throw new \RuntimeException('Email channel or out direction not found');
        }

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $now = new \DateTimeImmutable();

        $headers = [
            'from' => $conversation->getAccount()->getEndpoint(), // Use mail account endpoint as sender
            'to' => $to,
            'send_status' => 'draft',
            // LLM metadata for traceability and metrics
            'llm_model' => $llmResult['model'],
            'llm_persona' => $llmResult['persona'],
            'llm_approved' => $llmResult['approved'],
            'llm_fallback_used' => $llmResult['fallback_used'] ?? false,
            'llm_attempts' => $llmResult['attempts'],
            'llm_policy_flags' => $llmResult['policy_flags'],
            'llm_validation_reasons' => $llmResult['validation_reasons'],
            'llm_cost_estimate' => $llmResult['cost_estimate'],
        ];

        // Store pipeline trace if available
        if (isset($llmResult['pipeline_trace'])) {
            $headers['pipeline_trace'] = $llmResult['pipeline_trace'];
        }

        $message = new Message(
            $msgId,
            $conversation,
            $channelEmail,
            $directionOut,
            (string) $context['detected_language'],
            $subject,
            $replyText,
            $replyHtml,
            $headers,
            bin2hex(random_bytes(32)), // composite_hash
            null, // vector_id
            $parentMessage, // reply_to
            $now,
            $now,
            null // deleted_at
        );

        $this->em->persist($message);
        $this->em->flush();

        // Audit trail: log REPLY_GENERATED event
        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::REPLY_GENERATED,
            $convId,
            'generate_reply',
            'success',
            'conversation',
            $convId,
            [
                'persona' => $personaCode,
                'model' => $llmResult['model'] ?? 'unknown',
                'cost' => $llmResult['cost_estimate'] ?? 0,
                'attempts' => $llmResult['attempts'] ?? 1,
                'detected_language' => (string) $context['detected_language'],
                'fallback_used' => $llmResult['fallback_used'] ?? false,
            ],
        );

        return [
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'to' => $to,
            'subject' => $subject,
            'draft' => [
                'text' => $replyText,
                'html' => $replyHtml,
            ],
            'meta' => [
                'persona' => $context['persona'],
                'safelist_eligible' => $this->checkSafelist($to),
                'generated_at' => $now->format(DATE_ATOM),
                'parent_gmail_msg_id' => $parentGmailMsgId,  // Gmail Message ID du parent (pour n8n Reply operation)
            ],
        ];
    }

    /**
     * Compose headers for threaded email sending
     */
    /** @return array<string, mixed>|null */
    public function composeHeaders(string $msgId): ?array
    {
        $message = $this->messageHandler->getMessage($msgId);

        if (!$message) {
            return null;
        }

        $parent = $message->getReplyTo();

        if (!$parent) {
            throw new \RuntimeException('Message is not a reply');
        }

        // Build References header according to RFC
        $refs = [];
        $parentHeaders = $parent->getHeaders();

        if (!empty($parentHeaders['references'])) {
            $refs = preg_split('/\s+/', trim($parentHeaders['references'])) ?: [];
        }

        if (!empty($parentHeaders['in_reply_to']) && !in_array($parentHeaders['in_reply_to'], $refs, true)) {
            $refs[] = $parentHeaders['in_reply_to'];
        }

        if (!empty($parentHeaders['message_id'])) {
            $refs[] = $parentHeaders['message_id'];
        }

        // Keep only last 12 unique references
        $refs = array_slice(array_unique(array_filter($refs, 'is_string')), -12);

        $to = $message->getHeaders()['to'] ?? null;
        $from = $message->getHeaders()['from'] ?? null;

        if (!$to || !$from) {
            throw new \RuntimeException('Missing to/from headers');
        }

        // Run safety checks
        $checks = [
            'safelist_ok' => $this->checkSafelist($to),
            'kill_switch_off' => !$this->isKillSwitchActive(),
            'cadence_ok' => $this->checkCadence($message->getConversation()->getConvId()),
            'conversation_open' => $message->getConversation()->getStatus()->value === 'open',
        ];

        $safeToSend = $checks['safelist_ok'] && $checks['kill_switch_off'] && $checks['cadence_ok'] && $checks['conversation_open'];
        $rateLimited = !$checks['cadence_ok'];

        return [
            'msg_id' => $msgId,
            'to' => $to,
            'from' => $from,
            'subject' => $message->getSubject() ?? '',
            'in_reply_to' => $parentHeaders['message_id'] ?? null,
            'references' => implode(' ', $refs),
            'thread_id' => $parentHeaders['thread_id'] ?? null,
            'safe_to_send' => $safeToSend,
            'rate_limited' => $rateLimited,
            'checks' => $checks,
        ];
    }

    /**
     * Mark message as sent and store threading headers
     */
    /**
     * @param array<string, mixed>|null $sentHeaders
     */
    public function markAsSent(
        string $msgId,
        string $provider,
        string $providerMsgId,
        \DateTimeImmutable $tsSent,
        ?array $sentHeaders = null,
        ?string $convId = null
    ): bool {
        $message = $this->messageHandler->getMessage($msgId);

        if (!$message) {
            return false;
        }

        // Idempotency check
        if ($message->getSendStatus() === 'sent') {
            throw new \RuntimeException('Message already sent');
        }

        $message->setSendStatus('sent');
        $message->setProviderMsgId($providerMsgId);
        $message->setTsSent($tsSent);

        $conversation = $message->getConversation();

        // If conv_id is provided, verify it matches (security check)
        if ($convId !== null && $conversation->getConvId() !== $convId) {
            $this->logger->warning('[ReplyHandler] conv_id mismatch during markAsSent', [
                'expected' => $conversation->getConvId(),
                'received' => $convId,
            ]);
        }

        // Build proper threading headers from conversation context
        $currentHeaders = $message->getHeaders();

        // Get the last INCOMING message from the conversation to reply to
        // Direction is an entity, fetch it first
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        /** @var Message[] $inboundMessages */
        $inboundMessages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :conversation')
            ->andWhere('m.direction = :direction')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('direction', $directionIn)
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (count($inboundMessages) > 0) {
            /** @var Message $lastInbound */
            $lastInbound = $inboundMessages[0];
            $parentHeaders = $lastInbound->getHeaders();

            // Headers can be stored with either 'message-id' (with dash) or 'message_id' (with underscore)
            /** @var string|null $parentMessageId */
            $parentMessageId = $parentHeaders['message-id'] ?? $parentHeaders['message_id'] ?? null;
            /** @var string $parentReferences */
            $parentReferences = $parentHeaders['references'] ?? '';

            // Build RFC 5322 compliant headers
            if ($parentMessageId) {
                $currentHeaders['in_reply_to'] = $parentMessageId;

                // Build references: parent's references + parent's message_id
                $referencesArray = array_filter(explode(' ', trim($parentReferences)));
                $referencesArray[] = $parentMessageId;
                $currentHeaders['references'] = implode(' ', array_unique($referencesArray));

                $this->logger->debug('[ReplyHandler] Threading headers rebuilt', [
                    'in_reply_to' => $parentMessageId,
                ]);
            } else {
                $this->logger->warning('[ReplyHandler] No message_id in parent message headers');
            }
        } else {
            $this->logger->warning('[ReplyHandler] No incoming messages found in conversation');
        }

        // Store additional headers from n8n
        if ($sentHeaders !== null) {
            if (isset($sentHeaders['thread_id'])) {
                $currentHeaders['thread_id'] = $sentHeaders['thread_id'];
            }

            // Store the real RFC822 Message-ID if provided by n8n workflow
            if (isset($sentHeaders['message-id'])) {
                $rfc822MessageId = $sentHeaders['message-id'];

                // Clean chevrons if present (e.g., "<message-id>" -> "message-id")
                $rfc822MessageId = trim($rfc822MessageId, '<>');

                $currentHeaders['message-id'] = $rfc822MessageId;
                $this->logger->debug('[ReplyHandler] RFC822 Message-ID stored');
            }
        }

        $message->setHeaders($currentHeaders);

        $this->em->flush();

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::REPLY_SENT,
            $conversation->getConvId(),
            'mark_as_sent',
            'success',
            'message',
            $msgId,
            [
                'provider' => $provider,
                'provider_msg_id' => $providerMsgId,
            ],
        );

        return true;
    }

    /**
     * Check if email is in safelist
     */
    private function checkSafelist(string $email): bool
    {
        // Load safe domains from env var (comma-separated), with defaults for dev/test
        $envDomains = $_ENV['SCAMBUSTER_SAFE_DOMAINS'] ?? $_SERVER['SCAMBUSTER_SAFE_DOMAINS'] ?? '';
        $safeDomains = ['example.test', 'mailinator.com', 'guerrillamail.com'];

        if ($envDomains !== '') {
            $extraDomains = array_map('trim', explode(',', $envDomains));
            $safeDomains = array_merge($safeDomains, array_filter($extraDomains));
        }

        // Extract domain - handle invalid emails gracefully
        $atPos = strrchr($email, '@');

        if ($atPos === false) {
            // No @ sign found - invalid email
            return false;
        }

        $domain = strtolower(substr($atPos, 1));

        return in_array($domain, $safeDomains, true);
    }

    /**
     * Check if kill switch is active.
     *
     * Reads from SCAMBUSTER_KILL_SWITCH environment variable.
     * Any truthy value ('1', 'true', 'yes', 'on') activates the kill switch
     * and halts all automated reply generation and sending.
     */
    private function isKillSwitchActive(): bool
    {
        $value = $_ENV['SCAMBUSTER_KILL_SWITCH'] ?? $_SERVER['SCAMBUSTER_KILL_SWITCH'] ?? '0';

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check Redis-backed rate limits at three levels.
     *
     * Returns null if all limits pass, or a string describing which limit was exceeded.
     */
    private function checkRateLimits(string $convId): ?string
    {
        // Level 1: max replies per conversation per day
        if ($this->repliesPerConversationLimiter !== null) {
            $limiter = $this->repliesPerConversationLimiter->create($convId);
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyHandler] Rate limit exceeded: replies per conversation', [
                    'conv_id' => $convId,
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('conv_replies', $convId);

                return 'max replies per conversation per day';
            }
        }

        // Level 2: max LLM API calls per hour (global)
        if ($this->llmCallsPerHourLimiter !== null) {
            $limiter = $this->llmCallsPerHourLimiter->create('global');
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyHandler] Rate limit exceeded: LLM calls per hour', [
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('llm_calls_per_hour', $convId);

                return 'max LLM API calls per hour';
            }
        }

        // Level 3: max active conversations per day
        if ($this->activeConversationsPerDayLimiter !== null) {
            $limiter = $this->activeConversationsPerDayLimiter->create('global');
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[ReplyHandler] Rate limit exceeded: active conversations per day', [
                    'retry_after' => $limit->getRetryAfter()->format(DATE_ATOM),
                ]);
                $this->dispatchRateLimitAudit('active_conversations_per_day', $convId);

                return 'max active conversations per day';
            }
        }

        return null;
    }

    /**
     * Check cadence (minimum time between replies)
     */
    private function checkCadence(string $convId): bool
    {
        // Get last outgoing message in this conversation
        /** @var Message|null $lastOut */
        $lastOut = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->join('m.direction', 'd')
            ->where('m.conversation = :convId')
            ->andWhere('d.code = :out')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->setParameter('out', 'out')
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$lastOut) {
            return true; // No previous outgoing message
        }

        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $lastOut->getTsMsg()->getTimestamp();
        $hoursDiff = $diff / 3600;

        return $hoursDiff >= self::MIN_HOURS_BETWEEN_REPLIES;
    }

    /**
     * Detect language from the last inbound message in context.
     *
     * @param array<string, mixed> $context
     */
    private function detectLanguageFromContext(array $context): string
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = $context['last_messages'] ?? [];

        // Find last inbound message (direction=in)
        $lastInboundText = '';

        foreach (array_reverse($messages) as $msg) {
            if (($msg['direction'] ?? '') === 'in') {
                /** @var string $lastInboundText */
                $lastInboundText = $msg['body_text'] ?? '';

                break;
            }
        }

        if ($lastInboundText === '' || $this->languageDetector === null) {
            return 'en';
        }

        return $this->languageDetector->detect($lastInboundText);
    }

    private function dispatchRateLimitAudit(string $limitType, string $convId): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(
            eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
            actorId: 'system',
            action: 'rate_limit',
            outcome: 'blocked',
            resourceType: 'conversation',
            resourceId: $convId,
            details: ['limit_type' => $limitType],
            actorType: 'system'
        );
    }

    /**
     * Send a reply email via Symfony Mailer (SMTP).
     * Stateless: reads draft from DB, sends, returns Message-ID. Does NOT modify message state.
     *
     * @return array{success: bool, message_id: string, ts_sent: string}
     */
    public function sendEmail(string $msgId): array
    {
        if (!$this->mailer) {
            throw new \RuntimeException('Mailer not configured (MAILER_DSN missing or symfony/mailer not installed)');
        }

        $message = $this->messageHandler->getMessage($msgId);

        if (!$message) {
            throw new \RuntimeException('Message not found');
        }

        // Verify it's an outbound reply
        if ($message->getDirection()->getCode() !== 'out') {
            throw new \RuntimeException('Cannot send a non-outbound message');
        }

        // Get compose/threading data
        $compose = $this->composeHeaders($msgId);

        if (!$compose) {
            throw new \RuntimeException('Cannot compose headers for message');
        }

        if (!$compose['safe_to_send']) {
            throw new \RuntimeException('Safety checks failed: ' . json_encode($compose['checks']));
        }

        // Generate a local Message-ID
        $generatedMessageId = '<' . bin2hex(random_bytes(16)) . '@scambuster.local>';
        $tsSent = new \DateTimeImmutable();

        // Build the email
        $email = (new Email())
            ->from($compose['from'])
            ->to($compose['to'])
            ->subject($compose['subject']);

        // Set threading headers
        if (!empty($compose['in_reply_to'])) {
            $email->getHeaders()->addTextHeader('In-Reply-To', $compose['in_reply_to']);
        }
        if (!empty($compose['references'])) {
            $email->getHeaders()->addTextHeader('References', $compose['references']);
        }
        $email->getHeaders()->addTextHeader('Message-ID', $generatedMessageId);

        // Set body
        $bodyHtml = $message->getBodyHtml();
        $bodyText = $message->getBodyText();

        if ($bodyHtml) {
            $email->html($bodyHtml);
        }
        if ($bodyText) {
            $email->text($bodyText);
        }

        // Send
        $this->mailer->send($email);

        $this->logger->info('[ReplyHandler] Email sent via SMTP', [
            'msg_id' => $msgId,
            'to' => $compose['to'],
            'message_id' => $generatedMessageId,
        ]);

        return [
            'success' => true,
            'message_id' => $generatedMessageId,
            'ts_sent' => $tsSent->format(\DateTimeInterface::ATOM),
        ];
    }
}
