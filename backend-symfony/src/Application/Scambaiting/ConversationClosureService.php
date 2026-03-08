<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

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
final class ConversationClosureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationMetricsCollector $metricsCollector,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Ferme une conversation et dispatch l'événement ConversationEndedEvent.
     * Cette méthode doit être appelée par le workflow n8n WF-SCAMBAITING-END-CONVERSATION.
     *
     * @param string $convId ID de la conversation à fermer
     * @throws \RuntimeException Si la conversation n'existe pas ou est déjà fermée
     */
    public function closeConversation(string $convId): void
    {
        // 1. Récupérer la conversation
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if ($conversation === null) {
            throw new \RuntimeException("Conversation {$convId} not found");
        }

        if ($conversation->getDeletedAt() !== null) {
            throw new \RuntimeException("Conversation {$convId} is deleted");
        }

        // 2. Vérifier que la conversation n'est pas déjà fermée (idempotence check)
        if ($conversation->getStatus() === ConversationStatus::CLOSED) {
            $this->logger->warning('Conversation already closed, skipping event dispatch', [
                'conv_id' => $convId,
                'status' => $conversation->getStatus()->value,
            ]);
            return;
        }

        // 3. Collecter les métriques
        $metrics = $this->metricsCollector->collect($conversation);

        // 4. Calculer le reward
        $reward = $metrics->calculateReward();

        // 5. Mettre à jour conversation.engagement_duration_sec, turns_count, reward_value
        // (Ces colonnes sont déjà calculées en amont, mais on s'assure qu'elles existent)
        $conversation->setRewardValue($reward);
        $conversation->setStatus(ConversationStatus::CLOSED);

        $this->em->flush();

        // 6. Dispatcher l'événement
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

        $this->logger->info('Conversation closed and event dispatched', [
            'conv_id' => $convId,
            'reward' => $reward,
            'has_persona' => $event->hasPersona(),
        ]);
    }

    /**
     * Ferme plusieurs conversations en batch (pour CRON journalier).
     * Retourne le nombre de conversations fermées avec succès.
     *
     * @param string[] $convIds Liste des IDs de conversations à fermer
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
