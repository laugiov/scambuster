<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Domain\Communication\Persona;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonaPerformanceHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private PersonaPerformanceStatsRepository $statsRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getPerformance(string $personaCode): array
    {
        $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $personaCode]);

        if ($persona === null) {
            throw new \RuntimeException("Persona '{$personaCode}' not found");
        }

        $statsEntities = $this->statsRepository->findAllByPersona($persona);

        $performanceData = array_map(static function ($statsEntity): array {
            $performance = $statsEntity->toPersonaPerformance();

            return [
                'scam_type_code' => $performance->getScamTypeCode(),
                'sessions_count' => $performance->getSessionsCount(),
                'reward_avg' => $performance->getRewardAvg(),
                'is_cold_start' => $performance->isInColdStart(),
            ];
        }, $statsEntities);

        $globalAvgReward = 0.0;
        $totalSessions = 0;

        foreach ($statsEntities as $statsEntity) {
            $totalSessions += $statsEntity->getSessionsCount();
            $globalAvgReward += $statsEntity->getRewardAvg() * $statsEntity->getSessionsCount();
        }

        if ($totalSessions > 0) {
            $globalAvgReward /= $totalSessions;
        }

        return [
            'persona_code' => $personaCode,
            'persona_label' => $persona->getPersonaLabel(),
            'total_sessions' => $totalSessions,
            'global_avg_reward' => round($globalAvgReward, 4),
            'performance_by_scam_type' => $performanceData,
        ];
    }
}
