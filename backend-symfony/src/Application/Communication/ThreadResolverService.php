<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves email threads by matching Message-ID, In-Reply-To, and References headers.
 *
 * Extracted from IngestHandler (decomposition).
 * Handles: deduplication, thread resolution, conversation creation, and reopening.
 */
class ThreadResolverService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if a message with the given Message-ID already exists.
     *
     * @return array{msg_id: string, conv_id: string}|null existing message data, or null if not found
     */
    public function findExistingMessage(?string $messageId): ?array
    {
        if (!$messageId) {
            return null;
        }

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
            $this->logger->warning('[ThreadResolverService] Message already exists', [
                'message-id' => $messageId,
                'existing_msg_id' => $existingMsg['msg_id'],
                'existing_conv_id' => $existingMsg['conv_id'],
            ]);

            /** @var string $existMsgId */
            $existMsgId = $existingMsg['msg_id'];
            /** @var string $existConvId */
            $existConvId = $existingMsg['conv_id'];

            return [
                'msg_id' => $existMsgId,
                'conv_id' => $existConvId,
            ];
        }

        return null;
    }

    /**
     * Resolve the conversation for an incoming message by threading headers.
     *
     * Searches by In-Reply-To, then References, then reverse lookups.
     *
     * @param list<string> $references parsed references list
     *
     * @return array{conversation: ?Conversation, replyToMessage: ?Message}
     */
    public function resolveConversation(?string $inReplyTo, array $references, ?string $messageId): array
    {
        $conversation = null;
        $replyToMessage = null;

        // 1. Search by In-Reply-To
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

                if ($replyToMessage !== null) {
                    $conversation = $replyToMessage->getConversation();
                }
            }

            // Second lookup on headers.provider_msg_id.
            // sendEmail stores the message-id WITH chevrons in this field
            // (RFC 2822 full form). For 167/360 historical SMTP outbounds
            // ingested before provider_msg_id was persisted, this is the ONLY place the
            // identifier is reachable from the headers JSON. Without this
            // branch, every scammer reply to those outbounds creates an
            // orphan conversation.
            if (!$conversation) {
                $qbProv = $conn->createQueryBuilder();
                $qbProv->select('m.msg_id')
                    ->from('message', 'm')
                    ->where("m.headers->>'provider_msg_id' = :messageId")
                    ->orWhere("m.headers->>'provider_msg_id' = :messageIdWithChevrons")
                    ->andWhere('m.deleted_at IS NULL')
                    ->setParameter('messageId', $inReplyTo)
                    ->setParameter('messageIdWithChevrons', '<' . $inReplyTo . '>')
                    ->setMaxResults(1);

                $providerHit = $qbProv->executeQuery()->fetchOne();

                if ($providerHit !== false) {
                    $providerParent = $this->em->find(Message::class, $providerHit);

                    if ($providerParent !== null) {
                        $replyToMessage = $providerParent;
                        $conversation = $providerParent->getConversation();
                        $this->logger->info('[ThreadResolverService] Found conversation via provider_msg_id fallback', [
                            'in_reply_to' => $inReplyTo,
                            'conv_id' => $conversation->getConvId(),
                        ]);
                    }
                }
            }

            // Fallback: search in in_reply_to and references fields
            if (!$conversation) {
                $this->logger->info('[ThreadResolverService] Fallback: searching in in_reply_to/references fields', [
                    'searching_for' => $inReplyTo,
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

                    if ($fallbackMessage !== null) {
                        $conversation = $fallbackMessage->getConversation();
                        $this->logger->info('[ThreadResolverService] Found conversation via fallback search', [
                            'in_reply_to' => $inReplyTo,
                            'conv_id' => $conversation->getConvId(),
                        ]);
                    }
                }
            }
        }

        // 2. Search by References
        if (!$conversation && $references !== []) {
            $this->logger->info('[ThreadResolverService] Searching by references', ['references' => $references]);
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

                    if ($refMessage !== null) {
                        $conversation = $refMessage->getConversation();
                        $this->logger->info('[ThreadResolverService] Found conversation via references', [
                            'ref' => $ref,
                            'conv_id' => $conversation->getConvId(),
                        ]);

                        break;
                    }
                }
            }

            if (!$conversation) {
                $this->logger->info('[ThreadResolverService] No conversation found via references');
            }
        }

        // 3. Reverse lookup: search for messages that reference this message
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

                if ($referencingMessage !== null) {
                    $conversation = $referencingMessage->getConversation();
                }
            }
        }

        return ['conversation' => $conversation, 'replyToMessage' => $replyToMessage];
    }

    /**
     * Create a new conversation for an inbound message that has no thread match.
     */
    public function createNewConversation(
        ?string $fromHeader,
        ?string $messageId,
        MailAccount $account,
        Channel $channel,
        int $scoreRisk,
    ): Conversation {
        $this->logger->info('[ThreadResolverService] Creating new conversation');

        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'unknown'])
            ?? $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'UNKNOWN']);

        if ($scamType === null) {
            throw new \RuntimeException('Unknown scam_type');
        }

        $now = new \DateTimeImmutable();

        // Extract sender email
        $fromEmail = $fromHeader ? (preg_match('/<([^>]+)>/', $fromHeader, $matches) ? $matches[1] : $fromHeader) : '';

        // Defensive truncation + normalization to fit varchar(255).
        // Microsoft 365 internal Exchange senders emit X.400 DNs of ~150 chars; combined
        // with the message-id and chevrons, the legacy concat overflowed and PostgreSQL
        // rejected the INSERT (SQLSTATE 22001), breaking ingestion. Both variable parts
        // are clamped to 80 chars; total stixId stays under 200 chars.
        $fromEmail = substr($fromEmail, 0, 80);
        $normalizedMessageId = $messageId !== null ? substr(trim(trim($messageId), '<>'), 0, 80) : '';

        // Generate unique stixId
        $uniquePart = bin2hex(random_bytes(8));
        $stixId = 'shadow-ingest-' . $fromEmail . '-' . $normalizedMessageId . '-' . $uniquePart;

        $this->logger->info('[ThreadResolverService] Generated stixId', [
            'stixId' => $stixId,
            'fromEmail' => $fromEmail,
            'messageId' => $messageId,
        ]);

        $conversation = new Conversation(
            uuid_create(UUID_TYPE_RANDOM),
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            $scoreRisk,
            $now,
            $now,
            $stixId
        );
        $this->em->persist($conversation);

        try {
            $this->em->flush();
            $this->logger->info('[ThreadResolverService] Conversation created', ['conv_id' => $conversation->getConvId()]);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->error('[ThreadResolverService] Duplicate stixId detected', [
                'stixId' => $stixId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Conversation with this stixId already exists', $e->getCode(), $e);
        }

        return $conversation;
    }

    /**
     * Reopen a closed/abandoned conversation if policy allows it.
     */
    public function reopenIfNeeded(Conversation $conversation): void
    {
        if ($conversation->getStatus() === ConversationStatus::OPEN) {
            return;
        }

        $scamCode = $conversation->getScamType()->getCode();
        $policy = ConversationLifecycleConfig::getPolicy($scamCode);
        $previousStatus = $conversation->getStatus()->value;

        if (!$policy['allow_reopen']) {
            $this->logger->info('[ThreadResolverService] Reopen not allowed for scam type, message added to closed conversation', [
                'conv_id' => $conversation->getConvId(),
                'scam_type' => $scamCode,
                'previous_status' => $previousStatus,
            ]);

            return;
        }

        if ($policy['reopen_window_hours'] > 0) {
            $closedAt = $conversation->getUpdatedAt();
            $windowEnd = $closedAt->modify(sprintf('+%d hours', $policy['reopen_window_hours']));

            if (new \DateTimeImmutable() > $windowEnd) {
                $this->logger->info('[ThreadResolverService] Reopen window expired, message added to closed conversation', [
                    'conv_id' => $conversation->getConvId(),
                    'scam_type' => $scamCode,
                    'closed_at' => $closedAt->format('Y-m-d H:i'),
                    'window_hours' => $policy['reopen_window_hours'],
                ]);

                return;
            }

            $previousReward = $conversation->getRewardValue();
            $conversation->reopen();
            $conversation->resetRewardValue();
            // Explicit flush. Without it, downstream
            // Doctrine UoW change-tracking does NOT emit an UPDATE on the
            // `status` column (other writes to ts_last in the same tx flush
            // cleanly, but the enum-typed status change is silently lost).
            // Pre-existing bug exposed because Verrou A now relies on
            // reopen actually persisting. Affects all scam types that had
            // allow_reopen=true historically (ROMANCE/INVESTMENT/ADVANCE_FEE).
            $this->em->flush();

            $this->logger->info('[ThreadResolverService] Conversation reopened (within reopen window)', [
                'conv_id' => $conversation->getConvId(),
                'scam_type' => $scamCode,
                'previous_status' => $previousStatus,
                'previous_reward' => $previousReward,
            ]);
        } else {
            $previousReward = $conversation->getRewardValue();
            $conversation->reopen();
            $conversation->resetRewardValue();
            // Same persistence fix as the windowed branch.
            $this->em->flush();

            $this->logger->info('[ThreadResolverService] Conversation reopened on new inbound message', [
                'conv_id' => $conversation->getConvId(),
                'previous_status' => $previousStatus,
                'previous_reward' => $previousReward,
                'new_status' => 'open',
            ]);
        }
    }
}
