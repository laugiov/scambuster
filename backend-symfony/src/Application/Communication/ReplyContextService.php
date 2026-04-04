<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\LanguageDetector;
use App\Application\Scambaiting\PersonaOptimizer;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds conversation context for LLM reply generation.
 *
 * Responsibilities:
 * - Message retrieval
 * - Persona assignment (epsilon-greedy via PersonaOptimizer)
 * - IOC context assembly
 * - Sender history summary
 * - Language detection from inbound messages
 */
class ReplyContextService
{
    private const MIN_HOURS_BETWEEN_REPLIES = 6;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PersonaOptimizer $personaOptimizer,
        private readonly LoggerInterface $logger,
        private readonly ?ScamClassificationHandler $scamClassificationHandler = null,
        private readonly ?IocHandler $iocHandler = null,
        private readonly ?ConversationHistoryService $conversationHistoryService = null,
        private readonly ?LanguageDetector $languageDetector = null,
    ) {
    }

    public function getMessage(string $msgId): ?Message
    {
        return $this->em->getRepository(Message::class)->find($msgId);
    }

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
                'lang_detect' => $msg->getLangDetect(),
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

        // If no persona assigned yet, use epsilon-greedy optimizer to select best persona
        if ($persona === null) {
            $personaCode = $this->personaOptimizer->selectPersona($scamType->getCode());

            if ($personaCode !== null) {
                $persona = $this->em->getRepository(\App\Domain\Communication\Persona::class)
                    ->findOneBy(['personaCode' => $personaCode, 'isActive' => true]);

                if ($persona !== null) {
                    $conversation->setPersona($persona);
                    $this->em->flush();
                    $this->logger->info('[ReplyContextService] Persona assigned to conversation (epsilon-greedy optimized)', [
                        'conv_id' => $convId,
                        'persona_code' => $persona->getPersonaCode(),
                        'scam_type' => $scamType->getCode(),
                    ]);
                } else {
                    $this->logger->error('[ReplyContextService] Persona not found after optimization', [
                        'conv_id' => $convId,
                        'persona_code' => $personaCode,
                        'scam_type' => $scamType->getCode(),
                    ]);
                }
            } else {
                $this->logger->warning('[ReplyContextService] No persona selected by optimizer', [
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
                $this->logger->debug('[ReplyContextService] Failed to fetch IOCs', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Get sender history summary if service is available and there are inbound messages
        $senderHistorySummary = null;

        $this->logger->debug('[ReplyContextService] Checking for conversation history service', [
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
                        $this->logger->info('[ReplyContextService] Sender history summary generated', [
                            'conv_id' => $convId,
                            'sender_email' => $senderEmail,
                            'summary_length' => strlen($senderHistorySummary),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Graceful degradation: log error but continue without summary
                    $this->logger->warning('[ReplyContextService] Failed to generate sender history summary', [
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
     * Detect language from the last inbound message in context.
     *
     * @param array<string, mixed> $context
     */
    public function detectLanguageFromContext(array $context): string
    {
        /** @var array<int, array<string, mixed>> $messages */
        $messages = $context['last_messages'] ?? [];

        // Find last inbound message (direction=in)
        foreach (array_reverse($messages) as $msg) {
            if (($msg['direction'] ?? '') === 'in') {
                // Priority 1: use lang_detect already stored on the message (set by LLM at ingestion)
                $storedLang = $msg['lang_detect'] ?? null;

                if (\is_string($storedLang) && \strlen($storedLang) === 2) {
                    return $storedLang;
                }

                // Priority 2: fallback to trigram detection
                /** @var string $bodyText */
                $bodyText = $msg['body_text'] ?? '';

                if ($bodyText !== '' && $this->languageDetector !== null) {
                    return $this->languageDetector->detect($bodyText);
                }

                break;
            }
        }

        return 'en';
    }
}
