<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\Message;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates email ingestion: parsing, threading, message creation, and post-processing.
 *
 * Delegates to:
 * - EmailParsingService: RFC822 parsing, header extraction, body extraction, language detection
 * - ThreadResolverService: deduplication, thread resolution, conversation creation/reopen
 * - IngestPostProcessor: IOC extraction, scam classification, risk scoring, prompt injection, rate limiting
 */
class IngestHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly EmailParsingService $emailParser,
        private readonly ThreadResolverService $threadResolver,
        private readonly IngestPostProcessor $postProcessor,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?IocUpsertService $iocUpsertService = null,
        // Spec 065h — extracted from lines 50-73 of ingest()
        private readonly ?EntityReferenceResolver $referenceResolver = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ingest(IngestRawRequestDto $dto): array
    {
        $this->logger->info('[IngestHandler] Starting ingestion', [
            'account_id' => $dto->account_id,
            'channel' => $dto->channel,
            'score_risk' => $dto->score_risk,
        ]);

        // Spec 065h — delegate reference resolution to EntityReferenceResolver
        if ($this->referenceResolver !== null) {
            $refs = $this->referenceResolver->resolve($dto->account_id, $dto->channel ?? 'email');
            $account = $refs->account;
            $channel = $refs->channel;
            $direction = $refs->direction;
        } else {
            // Legacy inline fallback (backward compat for tests without the resolver)
            $account = $this->em->getRepository(\App\Domain\Communication\MailAccount::class)->find($dto->account_id);

            if (!$account) {
                throw new \RuntimeException('Unknown account_id');
            }
            $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy(['code' => $dto->channel ?? 'email']);

            if (!$channel) {
                throw new \RuntimeException('Unknown channel');
            }
            $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy(['code' => 'in']);

            if (!$direction) {
                throw new \RuntimeException('Unknown direction');
            }
        }

        // 1. Parse the raw email
        $rawSourceB64 = $dto->raw_source_rfc822_b64 ?? $dto->raw_source ?? null;

        if ($rawSourceB64 === null) {
            throw new \RuntimeException('Invalid raw_source');
        }

        $parsed = $this->emailParser->parseEmail($rawSourceB64);

        // 2. Deduplication check by Message-ID
        $existing = $this->threadResolver->findExistingMessage($parsed['messageId']);

        if ($existing !== null) {
            return [
                'msg_id' => $existing['msg_id'],
                'conv_id' => $existing['conv_id'],
                'status' => 'already_exists',
            ];
        }

        // 3. Resolve conversation by threading
        $threadResult = $this->threadResolver->resolveConversation(
            $parsed['inReplyTo'],
            $parsed['references'],
            $parsed['messageId'],
        );

        $conversation = $threadResult['conversation'];
        $replyToMessage = $threadResult['replyToMessage'];

        // 4. Create or reopen conversation
        if (!$conversation) {
            $conversation = $this->threadResolver->createNewConversation(
                $parsed['from'],
                $parsed['messageId'],
                $account,
                $channel,
                (int) $dto->score_risk,
            );
        } else {
            $this->logger->info('[IngestHandler] Using existing conversation', ['conv_id' => $conversation->getConvId()]);
            $this->threadResolver->reopenIfNeeded($conversation);
        }

        // 5. Create the message entity
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $now = new \DateTimeImmutable();

        // Build extra threat intel from DTO
        $extraThreatIntel = [];

        if (isset($dto->raw_headers_b64) && !empty($dto->raw_headers_b64)) {
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

        $messageEntity = new Message(
            $msgId,
            $conversation,
            $channel,
            $direction,
            $parsed['langDetect'],
            $parsed['subject'],
            $parsed['bodyText'],
            $parsed['bodyHtml'],
            array_merge(
                [
                    'rspamd' => $dto->rspamd,
                    'score_risk' => $dto->score_risk,
                    'channel' => $dto->channel,
                    'ts_received' => $dto->ts_received,
                    'from' => $parsed['from'],
                    'to' => $parsed['to'],
                    'date' => $parsed['date'],
                    'message-id' => $parsed['messageIdRaw'],
                    'in-reply-to' => $parsed['inReplyToRaw'],
                    'references' => $parsed['referencesRaw'],
                    'provider_msg_id' => $dto->provider_msg_id ?? null,
                ],
                $parsed['headers'],
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

        if (isset($dto->url_analysis) && !empty($dto->url_analysis)) {
            $messageEntity->setUrlAnalysis($dto->url_analysis);
        }

        $this->em->persist($messageEntity);

        // 6a. Spec 063 — Backend-side parser fallback for attachments.
        // If the upstream collector did not pre-populate dto.attachments
        // (e.g., n8n IMAP node since the 2026-03-31 Gmail->IMAP migration),
        // extract attachments by parsing the raw RFC822 source ourselves.
        // The fallback is invoked only when dto.attachments is null or empty,
        // so existing producers that already provide attachments (with
        // strelka/sandbox metadata) keep full control. Defensive: any
        // parser failure is caught and logged in extractAttachments(),
        // never propagated to the HTTP response.
        if ($dto->attachments === null || $dto->attachments === []) {
            $extracted = $this->emailParser->extractAttachments($rawSourceB64);

            if ($extracted !== []) {
                $this->logger->info('[IngestHandler] Parser fallback extracted attachments', [
                    'msg_id' => $msgId,
                    'count' => count($extracted),
                ]);
                $dto->attachments = $extracted;
            }
        }

        // 6b. Handle attachments (tightly coupled to entity creation)
        $this->processAttachments($dto, $messageEntity, $msgId, $now);

        // 7. Flush message + attachments
        try {
            $this->em->flush();
            $this->logger->info('[IngestHandler] Message created successfully', [
                'msg_id' => $msgId,
                'conv_id' => $conversation->getConvId(),
            ]);

            $this->auditLogger?->log(
                AuditEventType::MESSAGE_INGESTED,
                $conversation->getConvId(),
                'ingest_message',
                'success',
                'message',
                $msgId,
                ['channel' => $dto->channel ?? 'unknown'],
            );
        } catch (UniqueConstraintViolationException $e) {
            $this->handleUniqueConstraintViolation($e);
        }

        // 7b. Spec 064 — Link the mail's attachments' sha256 into the IOC
        // pipeline as observed_ioc rows of type sha256. Must run AFTER
        // em->flush() because IocUpsertService resolves the message via
        // the database. The method is defensive: failures per attachment
        // are caught and logged, never propagated to the HTTP response.
        //
        // Iterates $dto->attachments (post within-message dedup, pre
        // cross-message dedup) instead of $message->getAttachments() so
        // that two distinct mails carrying the same PDF each produce
        // their own observed_ioc row even though processAttachments
        // skipped persisting a duplicate attachment row (UNIQUE on
        // content_hash).
        $this->linkAttachmentsAsIocs($dto->attachments, $msgId, $now);

        // 8. Post-processing (IOCs, classification, risk, injection)
        $this->postProcessor->processAfterIngest($messageEntity, $conversation, $parsed['langDetect']);

        // 9. Check sender rate limits
        $senderRateLimited = $this->postProcessor->checkSenderRateLimits($parsed['from'], $conversation->getConvId());

        return [
            'msg_id' => $msgId,
            'conv_id' => $conversation->getConvId(),
            'status' => 'ingested',
            'rate_limited' => $senderRateLimited,
        ];
    }

    /**
     * Process and deduplicate attachments from the DTO.
     */
    private function processAttachments(IngestRawRequestDto $dto, Message $messageEntity, string $msgId, \DateTimeImmutable $now): void
    {
        if ($dto->attachments === null) {
            return;
        }

        // Deduplicate attachments by content_hash
        $seenHashes = [];
        $attachmentsToProcess = [];

        foreach ($dto->attachments as $attData) {
            $contentHash = $attData['sha256'];

            if (isset($seenHashes[$contentHash])) {
                $this->logger->warning('[IngestHandler] Duplicate attachment in same message, skipping', [
                    'content_hash' => $contentHash,
                    'filename' => $attData['filename'],
                ]);

                continue;
            }

            $seenHashes[$contentHash] = true;
            $attachmentsToProcess[] = $attData;
        }

        // Process deduplicated attachments
        foreach ($attachmentsToProcess as $attData) {
            $existingAttachment = $this->em->getRepository(Attachment::class)
                ->findOneBy(['contentHash' => $attData['sha256']]);

            if ($existingAttachment !== null) {
                $this->logger->info('[IngestHandler] Attachment already exists in database, skipping', [
                    'content_hash' => $attData['sha256'],
                    'filename' => $attData['filename'],
                    'existing_attachment_id' => $existingAttachment->getAttachmentId(),
                    'existing_msg_id' => $existingAttachment->getMessage()->getMsgId(),
                    'new_msg_id' => $msgId,
                ]);

                continue;
            }

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
                'duplicates_removed' => count($dto->attachments) - count($attachmentsToProcess),
            ]);
        }
    }

    /**
     * Spec 064 — Link the mail's attachments' sha256 hashes into the IOC
     * pipeline as observed_ioc rows of type sha256.
     *
     * Iterates the DTO attachment payload (not the persisted entities) so
     * that cross-message attachment dedup at the `attachment` table level
     * does not impair IOC pivot capability. IocUpsertService handles:
     *   - indicator dedup by (type, value_norm)
     *   - observed_ioc dedup by (msg_id, indicator_id) → 1 row per mail
     *   - spec 061 outgoing-message + honeypot guards
     *
     * Within-message dedup is performed inline below (same sha256 listed
     * twice in dto.attachments → 1 observed_ioc row). Cross-message dedup
     * is intentionally NOT applied here: 50 mails carrying the same
     * malicious PDF must produce 50 observed_ioc rows linked to 1
     * indicator, so analysts retain full pivot capability.
     *
     * Defensive: any per-attachment failure (InvalidArgumentException from
     * the spec 061 guards, RuntimeException from a missing message, or any
     * other Throwable) is caught and logged, then the loop continues with
     * the next attachment. The mail ingestion has already succeeded at
     * this point — losing the IOC linkage for one attachment is
     * acceptable, losing the entire mail is not.
     *
     * @param array<int, array<string, mixed>>|null $attachmentDataList
     */
    private function linkAttachmentsAsIocs(?array $attachmentDataList, string $msgId, \DateTimeImmutable $now): void
    {
        if ($this->iocUpsertService === null || $attachmentDataList === null || $attachmentDataList === []) {
            return;
        }

        $firstSeen = $now->format(\DateTimeImmutable::ATOM);
        $seenHashes = [];

        foreach ($attachmentDataList as $attData) {
            $contentHash = $attData['sha256'] ?? null;

            if (!is_string($contentHash) || $contentHash === '') {
                continue;
            }

            // Within-message dedup: same sha256 listed twice → 1 IOC.
            if (isset($seenHashes[$contentHash])) {
                continue;
            }
            $seenHashes[$contentHash] = true;

            $filename = isset($attData['filename']) && is_string($attData['filename']) ? $attData['filename'] : null;
            $mimeType = isset($attData['mime_type']) && is_string($attData['mime_type']) ? $attData['mime_type'] : null;
            $sizeBytes = isset($attData['size_bytes']) && is_int($attData['size_bytes']) ? $attData['size_bytes'] : null;

            try {
                $this->iocUpsertService->upsertEnrichedIoc([
                    'msg_id' => $msgId,
                    'ioc' => [
                        'type' => 'sha256',
                        'value' => $contentHash,
                        'value_norm' => strtolower($contentHash),
                        'source' => 'attachment',
                        'first_seen' => $firstSeen,
                    ],
                    'enrichment' => [
                        'filename' => $filename,
                        'mime_type' => $mimeType,
                        'size_bytes' => $sizeBytes,
                    ],
                    'category' => 'file-hash',
                    'tags' => ['attachment'],
                    'tlp' => 'AMBER',
                ]);
            } catch (\InvalidArgumentException $e) {
                // Spec 061 outgoing-message guard or honeypot filter.
                // Expected on outgoing messages — silent no-op, log at DEBUG.
                $this->logger->debug('[IngestHandler] Spec 061 guard blocked attachment IOC linkage', [
                    'msg_id' => $msgId,
                    'filename' => $filename,
                    'reason' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Any other failure (DB, missing message, etc.) — log WARNING
                // and continue with the next attachment.
                $this->logger->warning('[IngestHandler] Failed to link attachment sha256 as IOC', [
                    'msg_id' => $msgId,
                    'filename' => $filename,
                    'sha256' => $contentHash,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle UniqueConstraintViolationException with specific error messages.
     *
     * @throws \RuntimeException always
     */
    private function handleUniqueConstraintViolation(UniqueConstraintViolationException $e): never
    {
        $errorMessage = $e->getMessage();

        if (stripos($errorMessage, 'composite_hash') !== false) {
            $this->logger->error('[IngestHandler] Duplicate message detected (composite_hash)', [
                'error' => $errorMessage,
            ]);

            throw new \RuntimeException('Message already ingested (composite_hash conflict)');
        }

        if (stripos($errorMessage, 'content_hash') !== false) {
            $this->logger->error('[IngestHandler] Duplicate attachment detected (content_hash)', [
                'error' => $errorMessage,
                'note' => 'This should have been prevented by the check before persist',
            ]);

            throw new \RuntimeException('Attachment already exists (content_hash conflict)');
        }

        $this->logger->error('[IngestHandler] Unknown unique constraint violation', [
            'error' => $errorMessage,
        ]);

        throw new \RuntimeException('Database constraint violation: ' . $errorMessage);
    }
}
