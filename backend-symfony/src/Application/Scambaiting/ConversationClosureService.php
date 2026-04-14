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
 * Service pour fermer une conversation et dispatcher l'événement ConversationEndedEvent.
 * Point d'entrée principal pour la fin d'une conversation.
 */
final readonly class ConversationClosureService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConversationMetricsCollector $metricsCollector,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Close a conversation: compute engagement metrics, calculate reward, dispatch event.
     *
     * @param string $convId ID of the conversation to close
     * @param string $reason Why the conversation is being closed ('manual', 'stale_timeout', 'max_turns', 'max_duration')
     *
     * @throws \RuntimeException If the conversation does not exist or is deleted
     */
    public function closeConversation(string $convId, string $reason = 'manual'): void
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

        $conversation->setRewardValue($reward);
        $conversation->setStatus(ConversationStatus::CLOSED);

        $this->em->flush();

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::CONVERSATION_CLOSED,
            $convId,
            'close_conversation',
            'success',
            'conversation',
            $convId,
            [
                'reward' => $reward,
                'reason' => $reason,
            ],
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
     * Ferme plusieurs conversations en batch (pour CRON journalier).
     * Retourne le nombre de conversations fermées avec succès.
     *
     * @param string[] $convIds Liste des IDs de conversations à fermer
     *
     * @return int Nombre de conversations fermées
     */
    public function closeConversationsBatch(array $convIds): int
    {
        $closedCount = 0;

        foreach ($convIds as $convId) {
            try {
                $this->closeConversation($convId);
                $closedCount++;
            } catch (\Exception $e) {
                $this->logger->error('Failed to close conversation in batch', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Batch conversation closure completed', [
            'total' => count($convIds),
            'closed' => $closedCount,
            'failed' => count($convIds) - $closedCount,
        ]);

        return $closedCount;
    }
}
