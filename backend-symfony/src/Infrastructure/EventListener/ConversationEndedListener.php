<?php

declare(strict_types=1);

namespace App\Infrastructure\EventListener;

use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Domain\Scambaiting\ConversationMetrics;
use App\Domain\Scambaiting\Event\ConversationEndedEvent;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Event Listener qui réagit à la fin d'une conversation.
 * Calcule le reward et met à jour les statistiques de performance du persona.
 */
#[AsEventListener(event: ConversationEndedEvent::class, method: 'onConversationEnded')]
final class ConversationEndedListener
{
    public function __construct(
        private readonly PersonaPerformanceStatsRepository $statsRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Traite l'événement ConversationEndedEvent.
     * ⚠️ IMPORTANT : Cette méthode doit être TRANSACTIONNELLE pour éviter les race conditions.
     */
    public function onConversationEnded(ConversationEndedEvent $event): void
    {
        // Ignorer les conversations sans persona (pas d'apprentissage possible)
        if (!$event->hasPersona()) {
            $this->logger->info(
                'Conversation ended without persona, skipping performance update',
                ['conversation_id' => $event->getConversationId()]
            );

            return;
        }

        try {
            // 1. Calculer le reward
            $metrics = new ConversationMetrics(
                durationSec: $event->getDurationSec(),
                iocsTotal: $event->getIocsTotal(),
                iocsSensibles: $event->getIocsSensibles(),
                isCompleted: $event->isCompleted()
            );

            $reward = $metrics->calculateReward();

            $this->logger->info('Reward calculated', [
                'conversation_id' => $event->getConversationId(),
                'reward' => $reward,
                'metrics' => (string) $metrics,
            ]);

            // 2. Récupérer les entités Persona et ScamType
            $persona = $this->em->getRepository(Persona::class)->findOneBy([
                'personaCode' => $event->getPersonaCode(),
            ]);

            $scamType = $this->em->getRepository(ScamType::class)->findOneBy([
                'code' => $event->getScamTypeCode(),
            ]);

            if ($persona === null || $scamType === null) {
                $this->logger->error('Persona or ScamType not found', [
                    'persona_code' => $event->getPersonaCode(),
                    'scam_type_code' => $event->getScamTypeCode(),
                ]);

                return;
            }

            // 3. Mettre à jour les stats (ou créer si cold start)
            $this->em->beginTransaction();

            try {
                $stats = $this->statsRepository->findOrCreate($persona, $scamType);

                // Si nouvelle entité (cold start), on doit la persister
                if ($stats->getSessionsCount() === 0) {
                    $this->em->persist($stats);
                }

                $stats->addReward($reward);

                // 4. Mettre à jour la colonne reward_value dans Conversation
                $conversation = $this->em->getRepository(\App\Domain\Communication\Conversation::class)
                    ->find($event->getConversationId());

                if ($conversation !== null) {
                    $conversation->setRewardValue($reward);
                } else {
                    $this->logger->warning('Conversation not found for reward update', [
                        'conversation_id' => $event->getConversationId(),
                    ]);
                }

                $this->em->flush();
                $this->em->commit();

                $this->logger->info('Performance stats updated', [
                    'conversation_id' => $event->getConversationId(),
                    'persona_code' => $persona->getPersonaCode(),
                    'scam_type_code' => $scamType->getCode(),
                    'new_sessions_count' => $stats->getSessionsCount(),
                    'new_reward_avg' => $stats->getRewardAvg(),
                ]);
            } catch (\Exception $e) {
                $this->em->rollback();

                throw $e;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update performance stats', [
                'conversation_id' => $event->getConversationId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
