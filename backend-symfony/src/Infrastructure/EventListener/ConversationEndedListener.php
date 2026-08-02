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
 * Event Listener that reacts to the end of a conversation.
 * Calculates the reward and updates persona performance statistics.
 */
#[AsEventListener(event: ConversationEndedEvent::class, method: 'onConversationEnded')]
final readonly class ConversationEndedListener
{
    public function __construct(
        private PersonaPerformanceStatsRepository $statsRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {
    }
    /**
     * Handles the ConversationEndedEvent.
     * IMPORTANT: This method must be TRANSACTIONAL to avoid race conditions.
     */
    public function onConversationEnded(ConversationEndedEvent $event): void
    {
        // Ignore conversations without a persona (no learning possible)
        if (!$event->hasPersona()) {
            $this->logger->info(
                'Conversation ended without persona, skipping performance update',
                ['conversation_id' => $event->getConversationId()]
            );

            return;
        }

        try {
            // This listener's ONLY job: update PersonaPerformanceStats for bandit learning.
            // Prefer the reward carried on the event (the hybrid LLM-judged reward computed
            // by ConversationClosureService); fall back to the mechanical formula for event
            // sources that do not supply one.
            $metrics = new ConversationMetrics(
                durationSec: $event->getDurationSec(),
                iocsTotal: $event->getIocsTotal(),
                iocsSensibles: $event->getIocsSensibles(),
                isCompleted: $event->isCompleted(),
            );
            $reward = $event->getRewardOverride() ?? $metrics->calculateReward();

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
