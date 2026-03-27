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
            // Reward is already calculated and persisted by ConversationClosureService.
            // This listener's ONLY job: update PersonaPerformanceStats for bandit learning.
            $metrics = new ConversationMetrics(
                durationSec: $event->getDurationSec(),
                iocsTotal: $event->getIocsTotal(),
                iocsSensibles: $event->getIocsSensibles(),
                isCompleted: $event->isCompleted(),
            );
            $reward = $metrics->calculateReward();

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

            $this->em->beginTransaction();

            try {
                $stats = $this->statsRepository->findOrCreate($persona, $scamType);

                if ($stats->getSessionsCount() === 0) {
                    $this->em->persist($stats);
                }

                $stats->addReward($reward);

                $this->em->flush();
                $this->em->commit();

                $this->logger->info('Performance stats updated', [
                    'conversation_id' => $event->getConversationId(),
                    'persona_code' => $persona->getPersonaCode(),
                    'scam_type_code' => $scamType->getCode(),
                    'reward' => $reward,
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
            ]);
        }
    }
}
