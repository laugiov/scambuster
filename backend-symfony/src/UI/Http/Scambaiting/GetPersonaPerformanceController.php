<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Domain\Communication\Persona;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Retourne la performance d'un persona sur tous les scam_types.
 * Permet d'analyser les points forts et faibles d'un persona.
 */
#[Route('/api/v1/scambaiting/persona/{personaCode}/performance', name: 'api_scambaiting_persona_performance', methods: ['GET'])]
final class GetPersonaPerformanceController extends AbstractController
{
    public function __construct(
        private readonly PersonaPerformanceStatsRepository $statsRepository,
        private readonly EntityManagerInterface $em
    ) {}

    public function __invoke(string $personaCode): JsonResponse
    {
        // 1. Vérifier que le persona existe
        $persona = $this->em->getRepository(Persona::class)->findOneBy(['personaCode' => $personaCode]);

        if ($persona === null) {
            return new JsonResponse([
                'success' => false,
                'error' => "Persona '{$personaCode}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        // 2. Récupérer toutes les stats pour ce persona
        $statsEntities = $this->statsRepository->findAllByPersona($persona);

        // 3. Transformer en tableau JSON
        $performanceData = array_map(static function ($statsEntity) {
            $performance = $statsEntity->toPersonaPerformance();
            return [
                'scam_type_code' => $performance->getScamTypeCode(),
                'sessions_count' => $performance->getSessionsCount(),
                'reward_avg' => $performance->getRewardAvg(),
                'is_cold_start' => $performance->isInColdStart(),
            ];
        }, $statsEntities);

        // 4. Calculer le reward moyen global
        $globalAvgReward = 0.0;
        $totalSessions = 0;

        foreach ($statsEntities as $statsEntity) {
            $totalSessions += $statsEntity->getSessionsCount();
            $globalAvgReward += $statsEntity->getRewardAvg() * $statsEntity->getSessionsCount();
        }

        if ($totalSessions > 0) {
            $globalAvgReward /= $totalSessions;
        }

        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $personaCode,
                'persona_label' => $persona->getPersonaLabel(),
                'total_sessions' => $totalSessions,
                'global_avg_reward' => round($globalAvgReward, 4),
                'performance_by_scam_type' => $performanceData,
            ],
        ], Response::HTTP_OK);
    }
}
