<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\Audit\AuditLogger;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Service to close a conversation and dispatch the ConversationEndedEvent.
 * Main entry point for conversation closure.
 */
final readonly class ConversationClosureService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversationMetricsCollector $metricsCollector,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?AuditLogger $auditLogger = null,
        // Optional (nullable for DI/back-compat): blends the mechanical reward with
        // an LLM outcome judgement so the bandit learns from what was achieved.
        private ?RewardJudge $rewardJudge = null,
    ) {
    }

    /**
     * Close a conversation: compute engagement metrics, calculate reward, dispatch event.
     *
     * @param string $convId    ID of the conversation to close
     * @param string $reason    Why the conversation is being closed ('manual', 'inactivity (>Xh)', 'max_turns (N/M)', 'max_duration (>X days)')
     * @param string $actorId   the actor identifier emitted on CONVERSATION_CLOSED audit row.
     *                          Defaults to 'user' for legacy callers; controllers should pass the authenticated user id.
     *                          Cron callers pass 'cron'.
     * @param string $actorType 'user' for authenticated UI/API actions, 'system' for cron/automated closures.
     *
     * @throws \RuntimeException If the conversation does not exist or is deleted
     */
    public function closeConversation(string $convId, string $reason = 'manual', string $actorId = 'user', string $actorType = 'user'): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException("Conversation {$convId} not found");
        }

        if ($conversation->getDeletedAt() !== null) {
            throw new \RuntimeException("Conversation {$convId} is deleted");
        }

        if ($conversation->getStatus() === ConversationStatus::CLOSED) {
            $this->logger->warning('Conversation already closed, skipping event dispatch', [
                'conv_id' => $convId,
                'status' => $conversation->getStatus()->value,
            ]);

            return;
        }

        // Compute engagement metrics from actual message timestamps
        $msgMetrics = $this->computeMessageMetrics($convId);
        $conversation->setEngagementDurationSec($msgMetrics['duration_sec']);
        $conversation->setTurnsCount($msgMetrics['turns_count']);

        // Collect IOC metrics and calculate reward
        $isCompleted = !in_array($reason, ['stale_timeout', 'max_duration'], true);
        $metrics = $this->metricsCollector->collect($conversation, $isCompleted);
        $reward = $metrics->calculateReward();

        // Blend with an LLM outcome judgement (hybrid reward). Null-safe: without
        // the judge, or on any failure, this returns the mechanical reward.
        if ($this->rewardJudge instanceof RewardJudge) {
            $reward = $this->rewardJudge->hybrid($reward, $this->fetchRecentMessageBodies($convId), $metrics);
        }

        $conversation->setRewardValue($reward);
        $conversation->setStatus(ConversationStatus::CLOSED);

        $this->em->flush();

        // actor_id is the passed-in actor (was bugged to $convId);
        // actor_type carries 'system' for cron, 'user' for manual API closures.
        // resource_id still carries $convId — that's where the conv reference belongs.
        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::CONVERSATION_CLOSED,
            $actorId,
            'close_conversation',
            'success',
            'conversation',
            $convId,
            [
                'reward' => $reward,
                'reason' => $reason,
            ],
            null,
            null,
            $actorType,
        );

        $event = new ConversationEndedEvent(
            conversationId: $conversation->getConvId(),
            scamTypeCode: $conversation->getScamType()->getCode(),
            personaCode: $conversation->getPersona()?->getPersonaCode(),
            durationSec: $msgMetrics['duration_sec'],
            turnsCount: $msgMetrics['turns_count'],
            iocsTotal: $metrics->getIocsTotal(),
            iocsSensibles: $metrics->getIocsSensibles(),
            isCompleted: $isCompleted,
            rewardOverride: $reward,
        );

        $this->eventDispatcher->dispatch($event);

        $this->logger->info('Conversation closed and event dispatched', [
            'conv_id' => $convId,
            'reward' => $reward,
            'reason' => $reason,
            'duration_sec' => $msgMetrics['duration_sec'],
            'turns_count' => $msgMetrics['turns_count'],
        ]);
    }

    /**
     * Reopen a closed or abandoned conversation (manual analyst action).
     *
     * Reactivates the conversation and clears its recorded reward. The persona
     * statistics already credited to the bandit when it was closed are NOT rolled
     * back — the UI warns the analyst that closing it again will count the outcome
     * a second time. No ConversationEndedEvent is dispatched, so reopening on its
     * own never touches the bandit.
     *
     * @param string $convId    ID of the conversation to reopen
     * @param string $actorId   the actor identifier emitted on the CONVERSATION_REOPENED audit row
     * @param string $actorType 'user' for authenticated UI/API actions, 'system' for automated
     *
     * @throws \RuntimeException if the conversation does not exist, is deleted, or is a mistake
     */
    public function reopenConversation(string $convId, string $actorId = 'user', string $actorType = 'user'): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException("Conversation {$convId} not found");
        }

        if ($conversation->getDeletedAt() !== null) {
            throw new \RuntimeException("Conversation {$convId} is deleted");
        }

        $previousStatus = $conversation->getStatus();

        if ($previousStatus === ConversationStatus::OPEN) {
            $this->logger->warning('Conversation already open, skipping reopen', [
                'conv_id' => $convId,
            ]);

            return;
        }

        if ($previousStatus !== ConversationStatus::CLOSED && $previousStatus !== ConversationStatus::ABANDONED) {
            throw new \RuntimeException(
                "Conversation {$convId} cannot be reopened from status '{$previousStatus->value}'"
            );
        }

        $conversation->reopen();
        $conversation->resetRewardValue();

        $this->em->flush();

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::CONVERSATION_REOPENED,
            $actorId,
            'reopen_conversation',
            'success',
            'conversation',
            $convId,
            [
                'previous_status' => $previousStatus->value,
            ],
            null,
            null,
            $actorType,
        );

        $this->logger->info('Conversation reopened', [
            'conv_id' => $convId,
            'previous_status' => $previousStatus->value,
        ]);
    }

    /**
     * Recalculate metrics and reward for an already-closed conversation.
     * Used by the backfill command to fix historical conversations with missing/degenerate rewards.
     * Does NOT change the conversation status. Dispatches ConversationEndedEvent to update persona stats.
     */
    public function recalculateMetricsAndReward(string $convId): void
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException("Conversation {$convId} not found");
        }

        $msgMetrics = $this->computeMessageMetrics($convId);
        $conversation->setEngagementDurationSec($msgMetrics['duration_sec']);
        $conversation->setTurnsCount($msgMetrics['turns_count']);

        $metrics = $this->metricsCollector->collect($conversation);
        $reward = $metrics->calculateReward();
        $conversation->setRewardValue($reward);

        $this->em->flush();

        $event = new ConversationEndedEvent(
            conversationId: $conversation->getConvId(),
            scamTypeCode: $conversation->getScamType()->getCode(),
            personaCode: $conversation->getPersona()?->getPersonaCode(),
            durationSec: $msgMetrics['duration_sec'],
            turnsCount: $msgMetrics['turns_count'],
            iocsTotal: $metrics->getIocsTotal(),
            iocsSensibles: $metrics->getIocsSensibles(),
            isCompleted: true,
        );

        $this->eventDispatcher->dispatch($event);

        $this->logger->info('Conversation metrics and reward recalculated', [
            'conv_id' => $convId,
            'reward' => $reward,
            'duration_sec' => $msgMetrics['duration_sec'],
            'turns_count' => $msgMetrics['turns_count'],
        ]);
    }

    /**
     * Compute engagement duration and turns count from actual message timestamps.
     *
     * @return array{duration_sec: int, turns_count: int}
     */
    /**
     * Fetch recent message bodies (oldest→newest) for LLM outcome scoring.
     *
     * @return list<array{direction: string, body_text: string}>
     */
    private function fetchRecentMessageBodies(string $convId, int $limit = 16): array
    {
        $conn = $this->em->getConnection();
        // $limit is a trusted internal int (never user input) — inlined to avoid
        // driver quirks with bound parameters in a LIMIT clause.
        $limit = max(1, $limit);
        /** @var list<array{direction: mixed, body_text: mixed}> $rows */
        $rows = $conn->fetchAllAssociative(
            'SELECT direction, body_text FROM message
             WHERE conv_id = :convId AND deleted_at IS NULL
             ORDER BY ts_msg DESC LIMIT ' . $limit,
            ['convId' => $convId],
        );

        $messages = [];

        foreach (array_reverse($rows) as $row) {
            $direction = is_numeric($row['direction']) ? (int) $row['direction'] : 1;
            $messages[] = [
                'direction' => $direction === 2 ? 'out' : 'in',
                'body_text' => is_string($row['body_text']) ? $row['body_text'] : '',
            ];
        }

        return $messages;
    }

    /**
     * @return array{duration_sec: int, turns_count: int}
     */
    private function computeMessageMetrics(string $convId): array
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT COUNT(*) as turns, EXTRACT(EPOCH FROM (MAX(ts_msg) - MIN(ts_msg)))::int as duration_sec FROM message WHERE conv_id = :convId',
            ['convId' => $convId],
        );

        $rawDuration = $row['duration_sec'] ?? 0;
        $rawTurns = $row['turns'] ?? 0;

        return [
            'duration_sec' => max(0, \is_numeric($rawDuration) ? (int) $rawDuration : 0),
            'turns_count' => max(0, \is_numeric($rawTurns) ? (int) $rawTurns : 0),
        ];
    }

    /**
     * Closes several conversations in a batch (for the daily CRON).
     * Returns the number of successfully closed conversations.
     *
     * Signature changed from `array<string>` to
     * `array<array{conv_id: string, reason: string}>` so each conv carries
     * the real closure reason (e.g. 'inactivity (>48h)', 'max_turns (15/15)').
     * Previously the batch path discarded the reason and every cron-closed
     * conv was mis-tagged as 'manual' in audit_log.
     *
     * @param list<array{conv_id: string, reason: string}> $items     List of (conv_id, reason) pairs
     * @param string                                       $actorId   Defaults to 'cron' (the typical caller is CloseStaleConversationsCommand)
     * @param string                                       $actorType Defaults to 'system'
     *
     * @return int Number of closed conversations
     */
    public function closeConversationsBatch(array $items, string $actorId = 'cron', string $actorType = 'system'): int
    {
        $closedCount = 0;

        foreach ($items as $item) {
            $convId = $item['conv_id'];
            $reason = $item['reason'];

            try {
                $this->closeConversation($convId, $reason, $actorId, $actorType);
                $closedCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to close conversation in batch', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Batch conversation closure completed', [
            'total' => count($items),
            'closed' => $closedCount,
            'failed' => count($items) - $closedCount,
        ]);

        return $closedCount;
    }
}
