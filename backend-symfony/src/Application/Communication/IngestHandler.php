<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use ZBateson\MailMimeParser\MailMimeParser;

class IngestHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly IocHandler $iocHandler,
        private readonly ?PromptInjectionDetector $promptInjectionDetector = null,
        private readonly ?RateLimiterFactory $emailsPerSenderPerDayLimiter = null,
        private readonly ?SenderFloodDetector $senderFloodDetector = null,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Convert HTML to plain text for IOC extraction
     * Preserves line breaks and removes HTML tags
     */
    private function convertHtmlToText(string $html): string
    {
        // Remove script and style tags with their content (security)
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Replace common block elements with newlines
        $text = preg_replace('/<\/(div|p|br|h[1-6]|li|tr)>/i', "\n", $text);
        $text = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $text);

        // Replace list items with newlines and bullets
        $text = preg_replace('/<li[^>]*>/i', "\n• ", $text);

        // Remove remaining HTML tags
        $text = strip_tags($text);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text); // Multiple spaces → single space
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text); // Multiple newlines → double newline

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        return trim($text);
    }

    /**
     * @return array<string, mixed>
     */
    public function ingest(IngestRawRequestDto $dto): array
    {
        $this->logger->info('[IngestHandler] Starting ingestion', [
            'account_id' => $dto->account_id,
            'channel' => $dto->channel,
            'score_risk' => $dto->score_risk
        ]);

        // Validation et récupération des entités
        $account = $this->em->getRepository(MailAccount::class)->find($dto->account_id);

        if (!$account) {
            $this->logger->error('[IngestHandler] Unknown account_id', ['account_id' => $dto->account_id]);

            throw new \RuntimeException('Unknown account_id');
        }
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => $dto->channel ?? 'email']);

        if (!$channel) {
            $this->logger->error('[IngestHandler] Unknown channel', ['channel' => $dto->channel]);

            throw new \RuntimeException('Unknown channel');
        }
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$direction) {
            $this->logger->error('[IngestHandler] Unknown direction');

            throw new \RuntimeException('Unknown direction');
        }

        // 1. Extraire et normaliser les headers en premier
        $rawSourceB64 = $dto->raw_source_rfc822_b64 ?? $dto->raw_source ?? null;

        if ($rawSourceB64 === null) {
            throw new \RuntimeException('Invalid raw_source');
        }
        $rawSource = base64_decode($rawSourceB64, true);

        if ($rawSource === false) {
            throw new \RuntimeException('Invalid base64 in raw_source');
        }
        $parser = new MailMimeParser();

        try {
            $message = $parser->parse($rawSource, false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Mail parse error: ' . $e->getMessage());
        }

        // Extraire et normaliser les headers importants
        $fromHeader = $message->getHeaderValue('from');
        $messageIdHeader = $message->getHeaderValue('message-id');
        $inReplyToHeader = $message->getHeaderValue('in-reply-to');
        $referencesHeader = $message->getHeaderValue('references');

        $this->logger->info('[IngestHandler] Headers extracted', [
            'from' => $fromHeader,
            'message-id' => $messageIdHeader,
            'in-reply-to' => $inReplyToHeader,
            'references' => $referencesHeader
        ]);

        // Normaliser les Message-ID (enlever les chevrons)
        $normalizeMessageId = function (?string $id): ?string {
            if (!$id) {
                return null;
            }

            return trim($id, '<>');
        };

        $messageId = $normalizeMessageId($messageIdHeader);
        $inReplyTo = $normalizeMessageId($inReplyToHeader);

        // Parser le header References qui peut contenir plusieurs Message-IDs séparés par des espaces
        $referencesArray = [];

        if (is_string($referencesHeader) && $referencesHeader !== '') {
            // Splitter par espaces/retours à la ligne pour extraire tous les Message-IDs
            $split = preg_split('/[\s\r\n]+/', trim($referencesHeader));
            $referencesArray = $split !== false ? $split : [];
        }
        $references = array_filter(array_map($normalizeMessageId, $referencesArray));

        $this->logger->info('[IngestHandler] Parsed headers', [
            'messageId' => $messageId,
            'inReplyTo' => $inReplyTo,
            'references' => $references
        ]);

        // 1.5 Vérifier si ce message existe déjà (éviter les doublons)
        if ($messageId) {
            $conn = $this->em->getConnection();
            $qb = $conn->createQueryBuilder();
            $qb->select('m.msg_id', 'm.conv_id')
                ->from('message', 'm')
                ->where("m.headers->>'message-id' = :messageId")
                ->orWhere("m.headers->>'message-id' = :messageIdWithChevrons")
                ->andWhere('m.deleted_at IS NULL')
                ->setParameter('messageId', $messageId)
                ->setParameter('messageIdWithChevrons', '<' . $messageId . '>')
                ->setMaxResults(1);

            $existingMsg = $qb->executeQuery()->fetchAssociative();

            if ($existingMsg !== false) {
                $this->logger->warning('[IngestHandler] Message already exists, skipping', [
                    'message-id' => $messageId,
                    'existing_msg_id' => $existingMsg['msg_id'],
                    'existing_conv_id' => $existingMsg['conv_id']
                ]);

                return [
                    'msg_id' => $existingMsg['msg_id'],
                    'conv_id' => $existingMsg['conv_id'],
                    'status' => 'already_exists',
                ];
            }
        }

        // 2. Recherche de la conversation existante
        $conversation = null;
        $replyToMessage = null;

        // 2.1 Chercher par in-reply-to
        if ($inReplyTo) {
            $conn = $this->em->getConnection();
            $qb = $conn->createQueryBuilder();
            $qb->select('m.msg_id')
                ->from('message', 'm')
                ->where("m.headers->>'message-id' = :messageId")
                ->orWhere("m.headers->>'message-id' = :messageIdWithChevrons")
                ->andWhere('m.deleted_at IS NULL')
                ->setParameter('messageId', $inReplyTo)
                ->setParameter('messageIdWithChevrons', '<' . $inReplyTo . '>')
                ->setMaxResults(1);

            $replyToMessageId = $qb->executeQuery()->fetchOne();

            if ($replyToMessageId !== false) {
                $replyToMessage = $this->em->find(Message::class, $replyToMessageId);

                if ($replyToMessage) {
                    $conversation = $replyToMessage->getConversation();
                }
            }

            // FALLBACK: If not found by message-id, search in in_reply_to and references
            // This handles the case where we stored in_reply_to/references but not the real message_id
            if (!$conversation) {
                $this->logger->info('[IngestHandler] Fallback: searching in in_reply_to/references fields', [
                    'searching_for' => $inReplyTo
                ]);

                $qb2 = $conn->createQueryBuilder();
                $qb2->select('m.msg_id')
                    ->from('message', 'm')
                    ->where("m.headers->>'in_reply_to' = :messageId")
                    ->orWhere("m.headers->>'in_reply_to' = :messageIdWithChevrons")
                    ->orWhere("m.headers->>'references' LIKE :messageIdPattern")
                    ->orWhere("m.headers->>'references' LIKE :messageIdWithChevronsPattern")
                    ->andWhere('m.deleted_at IS NULL')
                    ->setParameter('messageId', $inReplyTo)
                    ->setParameter('messageIdWithChevrons', '<' . $inReplyTo . '>')
                    ->setParameter('messageIdPattern', '%' . $inReplyTo . '%')
                    ->setParameter('messageIdWithChevronsPattern', '%<' . $inReplyTo . '>%')
                    ->setMaxResults(1);

                $fallbackMessageId = $qb2->executeQuery()->fetchOne();

                if ($fallbackMessageId !== false) {
                    $fallbackMessage = $this->em->find(Message::class, $fallbackMessageId);

                    if ($fallbackMessage) {
                        $conversation = $fallbackMessage->getConversation();
                        $this->logger->info('[IngestHandler] Found conversation via fallback search', [
                            'in_reply_to' => $inReplyTo,
                            'conv_id' => $conversation->getConvId()
                        ]);
                    }
                }
            }
        }

        // 2.2 Chercher par references
        if (!$conversation && !empty($references)) {
            $this->logger->info('[IngestHandler] Searching by references', ['references' => $references]);
            $conn = $this->em->getConnection();

            foreach ($references as $ref) {
                $qb = $conn->createQueryBuilder();
                $qb->select('m.msg_id')
                    ->from('message', 'm')
                    ->where("m.headers->>'message-id' = :messageId")
                    ->orWhere("m.headers->>'message-id' = :messageIdWithChevrons")
                    ->andWhere('m.deleted_at IS NULL')
                    ->setParameter('messageId', $ref)
                    ->setParameter('messageIdWithChevrons', '<' . $ref . '>')
                    ->setMaxResults(1);

                $refMessageId = $qb->executeQuery()->fetchOne();

                if ($refMessageId !== false) {
                    $refMessage = $this->em->find(Message::class, $refMessageId);

                    if ($refMessage) {
                        $conversation = $refMessage->getConversation();
                        $this->logger->info('[IngestHandler] Found conversation via references', [
                            'ref' => $ref,
                            'conv_id' => $conversation->getConvId()
                        ]);

                        break;
                    }
                }
            }

            if (!$conversation) {
                $this->logger->info('[IngestHandler] No conversation found via references');
            }
        }

        // 2.3 Chercher les messages qui référencent ce message
        if (!$conversation && $messageId) {
            $conn = $this->em->getConnection();
            $qb = $conn->createQueryBuilder();
            $qb->select('m.msg_id')
                ->from('message', 'm')
                ->where("m.headers->>'in-reply-to' = :messageId")
                ->orWhere("m.headers->>'in-reply-to' = :messageIdWithChevrons")
                ->orWhere("m.headers->>'references' LIKE :messageIdPattern")
                ->orWhere("m.headers->>'references' LIKE :messageIdWithChevronsPattern")
                ->andWhere('m.deleted_at IS NULL')
                ->setParameter('messageId', $messageId)
                ->setParameter('messageIdWithChevrons', '<' . $messageId . '>')
                ->setParameter('messageIdPattern', '%' . $messageId . '%')
                ->setParameter('messageIdWithChevronsPattern', '%<' . $messageId . '>%')
                ->setMaxResults(1);

            $referencingMessageId = $qb->executeQuery()->fetchOne();

            if ($referencingMessageId !== false) {
                $referencingMessage = $this->em->find(Message::class, $referencingMessageId);

                if ($referencingMessage) {
                    $conversation = $referencingMessage->getConversation();
                }
            }
        }

        // 3. Créer une nouvelle conversation si nécessaire
        if (!$conversation) {
            $this->logger->info('[IngestHandler] Creating new conversation');

            $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'unknown']);

            if (!$scamType) {
                throw new \RuntimeException('Unknown scam_type');
            }
            $now = new \DateTimeImmutable();

            // Extraire l'email de l'expéditeur
            $fromEmail = $fromHeader ? (preg_match('/<([^>]+)>/', $fromHeader, $matches) ? $matches[1] : $fromHeader) : '';

            // Générer un stixId unique pour éviter les conflits
            // On ajoute un timestamp et un hash aléatoire pour garantir l'unicité
            $uniquePart = bin2hex(random_bytes(8));
            $stixId = 'shadow-ingest-' . $fromEmail . '-' . $messageId . '-' . $uniquePart;

            $this->logger->info('[IngestHandler] Generated stixId', [
                'stixId' => $stixId,
                'fromEmail' => $fromEmail,
                'messageId' => $messageId
            ]);

            $conversation = new Conversation(
                uuid_create(UUID_TYPE_RANDOM),
                $channel,
                $scamType,
                $account,
                ConversationStatus::OPEN,
                (int)$dto->score_risk,
                $now,
                $now,
                $stixId
            );
            $this->em->persist($conversation);

            try {
                $this->em->flush();
                $this->logger->info('[IngestHandler] Conversation created', ['conv_id' => $conversation->getConvId()]);
            } catch (UniqueConstraintViolationException $e) {
                $this->logger->error('[IngestHandler] Duplicate stixId detected', [
                    'stixId' => $stixId,
                    'error' => $e->getMessage()
                ]);

                throw new \RuntimeException('Conversation with this stixId already exists');
            }
        } else {
            $this->logger->info('[IngestHandler] Using existing conversation', ['conv_id' => $conversation->getConvId()]);

            // Auto-reopen conversation if it was closed/abandoned and we receive a new inbound message
            // Respects per-scam-type lifecycle policy (allow_reopen + reopen_window_hours)
            if ($conversation->getStatus() !== ConversationStatus::OPEN) {
                $scamCode = $conversation->getScamType()->getCode();
                $policy = ConversationLifecycleConfig::getPolicy($scamCode);
                $previousStatus = $conversation->getStatus()->value;

                if (!$policy['allow_reopen']) {
                    $this->logger->info('[IngestHandler] Reopen not allowed for scam type, message added to closed conversation', [
                        'conv_id' => $conversation->getConvId(),
                        'scam_type' => $scamCode,
                        'previous_status' => $previousStatus,
                    ]);
                } elseif ($policy['reopen_window_hours'] > 0) {
                    $closedAt = $conversation->getUpdatedAt();
                    $windowEnd = $closedAt->modify(sprintf('+%d hours', $policy['reopen_window_hours']));

                    if (new \DateTimeImmutable() > $windowEnd) {
                        $this->logger->info('[IngestHandler] Reopen window expired, message added to closed conversation', [
                            'conv_id' => $conversation->getConvId(),
                            'scam_type' => $scamCode,
                            'closed_at' => $closedAt->format('Y-m-d H:i'),
                            'window_hours' => $policy['reopen_window_hours'],
                        ]);
                    } else {
                        $previousReward = $conversation->getRewardValue();
                        $conversation->setStatus(ConversationStatus::OPEN);
                        $conversation->resetRewardValue();

                        $this->logger->info('[IngestHandler] Conversation reopened (within reopen window)', [
                            'conv_id' => $conversation->getConvId(),
                            'scam_type' => $scamCode,
                            'previous_status' => $previousStatus,
                            'previous_reward' => $previousReward,
                        ]);
                    }
                } else {
                    $previousReward = $conversation->getRewardValue();
                    $conversation->setStatus(ConversationStatus::OPEN);
                    $conversation->resetRewardValue();

                    $this->logger->info('[IngestHandler] Conversation reopened on new inbound message', [
                        'conv_id' => $conversation->getConvId(),
                        'previous_status' => $previousStatus,
                        'previous_reward' => $previousReward,
                        'new_status' => 'open',
                    ]);
                }
            }
        }

        // 4. Créer le message
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $now = new \DateTimeImmutable();

        // Extraire les autres headers et le contenu
        $to = $message->getHeaderValue('to') ?: null;
        $subject = $message->getHeaderValue('subject') ?: null;
        $date = $message->getHeaderValue('date') ?: null;
        $contentType = $message->getHeaderValue('content-type') ?: null;
        $bodyText = $message->getTextContent();
        $bodyHtml = $message->getHtmlContent();

        // Normaliser le contenu
        if ($bodyText !== null) {
            $bodyText = trim($bodyText);
        }

        if ($bodyHtml !== null) {
            $bodyHtml = trim($bodyHtml);
        }

        if ($bodyText === null || $bodyText === '') {
            $parts = preg_split("/\R\R/", $rawSource, 2);
            $bodyText = isset($parts[1]) ? ltrim($parts[1]) : '';
        }

        // Si pas de body_text mais body_html existe, convertir HTML en texte
        if ($bodyHtml && (!$bodyText || $bodyText === $bodyHtml || (stripos((string)$contentType, 'text/html') !== false && !$message->getTextContent()))) {
            $bodyText = $this->convertHtmlToText($bodyHtml);
        }

        // Collecter tous les headers
        $allHeaders = [];

        foreach ($message->getAllHeaders() as $header) {
            $allHeaders[strtolower($header->getName())] = $header->getValue();
        }

        // Ajouter les données enrichies si présentes
        $extraThreatIntel = [];

        // Gérer raw_headers ou raw_headers_b64
        if (isset($dto->raw_headers_b64) && !empty($dto->raw_headers_b64)) {
            // Décoder le base64
            $decodedHeaders = base64_decode($dto->raw_headers_b64, true);

            if ($decodedHeaders !== false) {
                $extraThreatIntel['raw_headers'] = $decodedHeaders;
            } else {
                $this->logger->warning('[IngestHandler] Invalid base64 for raw_headers_b64');
            }
        } elseif (isset($dto->raw_headers) && !empty($dto->raw_headers)) {
            $extraThreatIntel['raw_headers'] = $dto->raw_headers;
        }

        if (isset($dto->parsed) && !empty($dto->parsed)) {
            $extraThreatIntel['parsed'] = $dto->parsed;
        }

        // Créer le message avec tous les headers normalisés
        $messageEntity = new Message(
            $msgId,
            $conversation,
            $channel,
            $direction,
            'fr',
            $subject,
            $bodyText,
            $bodyHtml,
            array_merge(
                [
                    'rspamd' => $dto->rspamd,
                    'score_risk' => $dto->score_risk,
                    'channel' => $dto->channel,
                    'ts_received' => $dto->ts_received,
                    'from' => $fromHeader,
                    'to' => $to,
                    'date' => $date,
                    'message-id' => $messageIdHeader,
                    'in-reply-to' => $inReplyToHeader,
                    'references' => $referencesHeader,
                    'provider_msg_id' => $dto->provider_msg_id ?? null, // Gmail Message ID for threading
                ],
                $allHeaders,
                $extraThreatIntel
            ),
            bin2hex(random_bytes(32)), // composite_hash
            null, // vector_id
            $replyToMessage,
            $now,
            $now,
            null // deleted_at
        );
        $messageEntity->setRawSource($rawSourceB64);

        // Store URL analysis if present
        if (isset($dto->url_analysis) && !empty($dto->url_analysis)) {
            $messageEntity->setUrlAnalysis($dto->url_analysis);
        }

        $this->em->persist($messageEntity);

        // Gérer les pièces jointes si présentes
        if ($dto->attachments !== null) {
            // Dédupliquer les pièces jointes par content_hash pour éviter les violations de contrainte unique
            $seenHashes = [];
            $attachmentsToProcess = [];

            foreach ($dto->attachments as $attData) {
                $contentHash = $attData['sha256'];

                if (isset($seenHashes[$contentHash])) {
                    // Cette PJ est un doublon dans le même message, on la saute
                    $this->logger->warning('[IngestHandler] Duplicate attachment in same message, skipping', [
                        'content_hash' => $contentHash,
                        'filename' => $attData['filename']
                    ]);

                    continue;
                }

                $seenHashes[$contentHash] = true;
                $attachmentsToProcess[] = $attData;
            }

            // Traiter les pièces jointes dédupliquées
            foreach ($attachmentsToProcess as $attData) {
                // Vérifier si cette PJ existe déjà dans la BDD (même hash dans un message précédent)
                // Note: content_hash is stored as the sha256 hex string itself (64 bytes in bytea)
                $existingAttachment = $this->em->getRepository(Attachment::class)
                    ->findOneBy(['contentHash' => $attData['sha256']]);

                if ($existingAttachment !== null) {
                    // La PJ existe déjà dans un message précédent, on la saute pour éviter une erreur 409
                    $this->logger->info('[IngestHandler] Attachment already exists in database, skipping', [
                        'content_hash' => $attData['sha256'],
                        'filename' => $attData['filename'],
                        'existing_attachment_id' => $existingAttachment->getAttachmentId(),
                        'existing_msg_id' => $existingAttachment->getMessage()->getMsgId(),
                        'new_msg_id' => $msgId
                    ]);

                    continue;
                }

                // Nouvelle PJ, on la crée normalement
                $attachment = new Attachment(
                    uuid_create(UUID_TYPE_RANDOM),
                    $messageEntity,
                    $attData['filename'],
                    $attData['mime_type'],
                    $attData['size_bytes'],
                    $attData['sha256'],
                    null, // s3Key
                    null, // encKeyId
                    'pending', // avStatus
                    null, // ocrText
                    null, // vectorId
                    $now, // tsIngest
                    null // deletedAt
                );

                if (isset($attData['strelka']) || isset($attData['sandbox'])) {
                    $metadata = [];

                    if (isset($attData['strelka'])) {
                        $metadata['strelka'] = $attData['strelka'];
                    }

                    if (isset($attData['sandbox'])) {
                        $metadata['sandbox'] = $attData['sandbox'];
                    }
                    $attachment->setMetadata($metadata);
                }

                $messageEntity->addAttachment($attachment);
                $this->em->persist($attachment);
            }

            if (count($dto->attachments) > count($attachmentsToProcess)) {
                $this->logger->info('[IngestHandler] Deduplicated attachments', [
                    'original_count' => count($dto->attachments),
                    'deduplicated_count' => count($attachmentsToProcess),
                    'duplicates_removed' => count($dto->attachments) - count($attachmentsToProcess)
                ]);
            }
        }

        try {
            $this->em->flush();
            $this->logger->info('[IngestHandler] Message created successfully', [
                'msg_id' => $msgId,
                'conv_id' => $conversation->getConvId()
            ]);

            $this->auditLogger?->log(
                \App\Domain\Audit\AuditEventType::MESSAGE_INGESTED,
                $conversation->getConvId(),
                'ingest_message',
                'success',
                'message',
                $msgId,
                ['channel' => $dto->channel ?? 'unknown'],
            );
        } catch (UniqueConstraintViolationException $e) {
            $errorMessage = $e->getMessage();

            // Distinguer les différents types de violations de contrainte unique
            if (stripos($errorMessage, 'composite_hash') !== false) {
                $this->logger->error('[IngestHandler] Duplicate message detected (composite_hash)', [
                    'error' => $errorMessage
                ]);

                throw new \RuntimeException('Message already ingested (composite_hash conflict)');
            } elseif (stripos($errorMessage, 'content_hash') !== false) {
                $this->logger->error('[IngestHandler] Duplicate attachment detected (content_hash)', [
                    'error' => $errorMessage,
                    'note' => 'This should have been prevented by the check before persist'
                ]);

                throw new \RuntimeException('Attachment already exists (content_hash conflict)');
            } else {
                // Autre type de violation de contrainte unique
                $this->logger->error('[IngestHandler] Unknown unique constraint violation', [
                    'error' => $errorMessage
                ]);

                throw new \RuntimeException('Database constraint violation: ' . $errorMessage);
            }
        }

        // Extract header-based IOCs (from, reply-to, return-path, message-id, subject, spf/dkim/dmarc)
        try {
            $headerIocsCount = $this->iocHandler->extractAndUpsertHeaderIocs($messageEntity);
            $this->logger->info('[IngestHandler] Header IOCs extracted', [
                'msg_id' => $msgId,
                'header_iocs_count' => $headerIocsCount
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail ingestion - header IOC extraction is non-critical
            $this->logger->error('[IngestHandler] Failed to extract header IOCs', [
                'msg_id' => $msgId,
                'error' => $e->getMessage()
            ]);
        }

        // Prompt injection forensic analysis (non-blocking, inbound messages only)
        if ($this->promptInjectionDetector !== null) {
            try {
                $analysis = $this->promptInjectionDetector->analyze($messageEntity);

                if ($analysis !== null) {
                    $messageEntity->setInjectionAnalysis($analysis->toArray());
                    $this->em->flush();
                    $this->logger->info('[IngestHandler] Prompt injection analysis complete', [
                        'msg_id' => $msgId,
                        'risk_score' => $analysis->getRiskScore(),
                        'high_risk' => $analysis->isHighRisk(),
                    ]);

                    if ($analysis->isHighRisk()) {
                        $this->auditLogger?->log(
                            \App\Domain\Audit\AuditEventType::INJECTION_DETECTED,
                            $conversation->getConvId(),
                            'injection_detected',
                            'blocked',
                            'message',
                            $msgId,
                            [
                                'risk_score' => $analysis->getRiskScore(),
                                'conv_id' => $conversation->getConvId(),
                            ],
                        );
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('[IngestHandler] Prompt injection analysis failed', [
                    'msg_id' => $msgId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Check sender rate limits (after ingest, before signaling reply-ok)
        $senderRateLimited = $this->checkSenderRateLimits($fromHeader, $conversation->getConvId());

        return [
            'msg_id' => $msgId,
            'conv_id' => $conversation->getConvId(),
            'status' => 'ingested',
            'rate_limited' => $senderRateLimited,
        ];
    }

    /**
     * Check per-sender rate limits: daily cap + flood burst detection.
     * Returns true if rate limited (reply should be skipped).
     */
    private function checkSenderRateLimits(?string $fromHeader, string $convId): bool
    {
        if ($fromHeader === null || $fromHeader === '') {
            return false;
        }

        $senderEmail = preg_match('/<([^>]+)>/', $fromHeader, $m) ? $m[1] : $fromHeader;
        $senderHash = hash('sha256', strtolower($senderEmail));

        // 1. Flood detection (burst: 5 emails in 5 minutes)
        if ($this->senderFloodDetector !== null) {
            $flooded = $this->senderFloodDetector->recordAndCheck($senderHash);

            if ($flooded) {
                $this->logger->warning('[IngestHandler] Sender flood detected', [
                    'sender_hash' => substr($senderHash, 0, 12),
                    'conv_id' => $convId,
                ]);

                $this->dispatchRateLimitAudit($senderHash, 'sender_flood', $convId);

                return true;
            }
        }

        // 2. Daily sender limit (10 emails/24h via Symfony rate limiter)
        if ($this->emailsPerSenderPerDayLimiter !== null) {
            $limiter = $this->emailsPerSenderPerDayLimiter->create($senderHash);
            $limit = $limiter->consume();

            if (!$limit->isAccepted()) {
                $this->logger->warning('[IngestHandler] Sender daily rate limit exceeded', [
                    'sender_hash' => substr($senderHash, 0, 12),
                    'conv_id' => $convId,
                    'retry_after' => $limit->getRetryAfter()->format(\DATE_ATOM),
                ]);

                $this->dispatchRateLimitAudit($senderHash, 'sender_daily', $convId);

                return true;
            }
        }

        return false;
    }

    private function dispatchRateLimitAudit(string $senderHash, string $limitType, string $convId): void
    {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(
            eventType: AuditEventType::RATE_LIMIT_EXCEEDED,
            actorId: substr($senderHash, 0, 12),
            action: 'rate_limit',
            outcome: 'blocked',
            resourceType: 'conversation',
            resourceId: $convId,
            details: ['limit_type' => $limitType],
            actorType: 'sender'
        );
    }
}
