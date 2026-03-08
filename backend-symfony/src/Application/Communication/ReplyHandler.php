<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\ReplyOrchestrator;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class ReplyHandler
{
    private const MIN_HOURS_BETWEEN_REPLIES = 6;
    private const MAX_REPLIES_PER_DAY = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private MessageHandler $messageHandler,
        private ReplyOrchestrator $replyOrchestrator,
        private LoggerInterface $logger,
        private PersonaManager $personaManager,
        private \App\Application\Scambaiting\PersonaOptimizer $personaOptimizer,
        private ?ScamClassificationHandler $scamClassificationHandler = null,
        private ?IocHandler $iocHandler = null,
        private ?ConversationHistoryService $conversationHistoryService = null
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
                if ($msg['direction'] === 'in' && isset($msg['headers']['from'])) {
                    $firstInboundMsg = $msg;

                    break;
                }
            }

            if ($firstInboundMsg !== null) {
                $senderEmail = $firstInboundMsg['headers']['from'];

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
    public function generateReply(string $convId, string $lastMsgId, bool $force = false, string $reason = 'manual'): ?array
    {
        // getConversationContext() handles automatic classification if needed
        $context = $this->getConversationContext($convId);

        if (!$context) {
            return null;
        }

        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation->getStatus()->value !== 'open') {
            throw new \RuntimeException('Cannot generate reply for closed conversation');
        }

        // Check cadence
        if (!$force && !$this->checkCadence($convId)) {
            throw new \RuntimeException('Cadence limit not met');
        }

        $parentMessage = $this->messageHandler->getMessage($lastMsgId);

        if (!$parentMessage) {
            return null;
        }

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

        $message = new Message(
            $msgId,
            $conversation,
            $channelEmail,
            $directionOut,
            'fr', // TODO: Detect from context
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
            $refs = preg_split('/\s+/', trim($parentHeaders['references']));
        }

        if (!empty($parentHeaders['in_reply_to']) && !in_array($parentHeaders['in_reply_to'], $refs, true)) {
            $refs[] = $parentHeaders['in_reply_to'];
        }

        if (!empty($parentHeaders['message_id'])) {
            $refs[] = $parentHeaders['message_id'];
        }

        // Keep only last 12 unique references
        $refs = array_slice(array_unique($refs), -12);

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
            error_log("[WARNING] conv_id mismatch: expected {$conversation->getConvId()}, got {$convId}");
            // Continue anyway, but log the mismatch
        }

        // Build proper threading headers from conversation context
        $currentHeaders = $message->getHeaders();

        // Get the last INCOMING message from the conversation to reply to
        // Direction is an entity, fetch it first
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

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
            $lastInbound = $inboundMessages[0];
            $parentHeaders = $lastInbound->getHeaders();

            // Headers can be stored with either 'message-id' (with dash) or 'message_id' (with underscore)
            $parentMessageId = $parentHeaders['message-id'] ?? $parentHeaders['message_id'] ?? null;
            $parentReferences = $parentHeaders['references'] ?? '';

            // Build RFC 5322 compliant headers
            if ($parentMessageId) {
                $currentHeaders['in_reply_to'] = $parentMessageId;

                // Build references: parent's references + parent's message_id
                $referencesArray = array_filter(explode(' ', trim($parentReferences)));
                $referencesArray[] = $parentMessageId;
                $currentHeaders['references'] = implode(' ', array_unique($referencesArray));

                error_log("[INFO] Rebuilt threading headers: in_reply_to={$parentMessageId}");
            } else {
                error_log('[WARNING] No message_id found in parent message headers');
            }
        } else {
            error_log('[WARNING] No INCOMING messages found in conversation');
        }

        // Store additional headers from n8n
        if ($sentHeaders !== null && is_array($sentHeaders)) {
            if (isset($sentHeaders['thread_id'])) {
                $currentHeaders['thread_id'] = $sentHeaders['thread_id'];
            }

            // Store the real RFC822 Message-ID if provided by n8n workflow
            if (isset($sentHeaders['message-id'])) {
                $rfc822MessageId = $sentHeaders['message-id'];

                // Clean chevrons if present (e.g., "<message-id>" -> "message-id")
                $rfc822MessageId = trim($rfc822MessageId, '<>');

                $currentHeaders['message-id'] = $rfc822MessageId;
                error_log("[INFO] RFC822 Message-ID stored: {$rfc822MessageId}");
            }
        }

        $message->setHeaders($currentHeaders);

        $this->em->flush();

        return true;
    }

    /**
     * Check if email is in safelist
     */
    private function checkSafelist(string $email): bool
    {
        // TODO: Load from config/env
        $safeDomains = ['example.test', 'mailinator.com', 'guerrillamail.com'];

        // Extract domain - handle invalid emails gracefully
        $atPos = strrchr($email, '@');

        if ($atPos === false) {
            // No @ sign found - invalid email
            return false;
        }

        $domain = substr($atPos, 1);

        return in_array($domain, $safeDomains, true);
    }

    /**
     * Check if kill switch is active
     */
    private function isKillSwitchActive(): bool
    {
        // TODO: Load from config/env
        return false;
    }

    /**
     * Check cadence (minimum time between replies)
     */
    private function checkCadence(string $convId): bool
    {
        // Get last outgoing message in this conversation
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

}
