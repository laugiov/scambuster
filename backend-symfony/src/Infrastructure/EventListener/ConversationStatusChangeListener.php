<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Application\Scambaiting\ConversationMetricsCollector;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Detects conversation status changes to CLOSED that bypass ConversationClosureService.
 * Ensures the bandit learning loop is always triggered, regardless of how the closure happens.
 *
 * Uses onFlush to detect the change (UoW changeset is available) and postFlush to act.
 * Registered via services.yaml with doctrine.event_listener tags.
 */
final class ConversationStatusChangeListener
{
    /** @var array<string, Conversation> */
    private array $pendingOrphanClosures = [];

    public function __construct(
        private readonly ConversationMetricsCollector $metricsCollector,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Conversation) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);

            if (!isset($changeSet['status'])) {
                continue;
            }

            $newStatus = $changeSet['status'][1];

            // Doctrine may pass the backed string value or the enum instance
            $isClosed = ($newStatus instanceof ConversationStatus)
                ? $newStatus === ConversationStatus::CLOSED
                : $newStatus === ConversationStatus::CLOSED->value;

            if (!$isClosed) {
                continue;
            }

            if ($entity->getRewardValue() !== null) {
                continue;
            }

            $this->pendingOrphanClosures[$entity->getConvId()] = $entity;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendingOrphanClosures === []) {
            return;
        }

        $pending = $this->pendingOrphanClosures;
        $this->pendingOrphanClosures = [];

        $em = $args->getObjectManager();

        foreach ($pending as $convId => $conversation) {
            if ($conversation->getRewardValue() !== null) {
                continue;
            }

            try {
                $metrics = $this->metricsCollector->collect($conversation);
                $reward = $metrics->calculateReward();

                $conversation->setRewardValue($reward);
                $em->flush();

                $event = new ConversationEndedEvent(
                    conversationId: $conversation->getConvId(),
                    scamTypeCode: $conversation->getScamType()->getCode(),
                    personaCode: $conversation->getPersona()?->getPersonaCode(),
                    durationSec: $metrics->getDurationSec(),
                    turnsCount: $conversation->getTurnsCount(),
                    iocsTotal: $metrics->getIocsTotal(),
                    iocsSensibles: $metrics->getIocsSensibles(),
                    isCompleted: $metrics->isCompleted()
                );

                $this->eventDispatcher->dispatch($event);

                $this->logger->info('Orphan closure: reward calculated and event dispatched', [
                    'conv_id' => $convId,
                    'reward' => $reward,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process orphan closure', [
                    'conv_id' => $convId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
