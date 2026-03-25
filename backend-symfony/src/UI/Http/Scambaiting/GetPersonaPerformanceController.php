<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Domain\Communication\Persona;
use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Retourne la performance d'un persona sur tous les scam_types.
 * Permet d'analyser les points forts et faibles d'un persona.
 */
#[OA\Get(
    path: '/api/v1/scambaiting/persona/{personaCode}/performance',
    summary: 'Get performance of a persona across all scam types',
    tags: ['Scambaiting'],
    parameters: [
        new OA\Parameter(name: 'personaCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'elderly_person')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Persona performance data',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'persona_code', type: 'string'),
                            new OA\Property(property: 'persona_label', type: 'string'),
                            new OA\Property(property: 'total_sessions', type: 'integer'),
                            new OA\Property(property: 'global_avg_reward', type: 'number', format: 'float'),
                            new OA\Property(
                                property: 'performance_by_scam_type',
                                type: 'array',
                                items: new OA\Items(
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'scam_type_code', type: 'string'),
                                        new OA\Property(property: 'sessions_count', type: 'integer'),
                                        new OA\Property(property: 'reward_avg', type: 'number', format: 'float'),
                                        new OA\Property(property: 'is_cold_start', type: 'boolean'),
                                    ]
                                )
                            ),
                        ]
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Persona not found',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/persona/{personaCode}/performance', name: 'api_scambaiting_persona_performance', methods: ['GET'])]
final class GetPersonaPerformanceController extends AbstractController
{
    public function __construct(
        private readonly PersonaPerformanceStatsRepository $statsRepository,
        private readonly EntityManagerInterface $em
    ) {
    }

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
