<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\ScambaitingStatsQueryService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Returns aggregated statistics for all scam_types.
 * Shows a global performance overview.
 */
#[OA\Get(
    path: '/api/v1/scambaiting/stats',
    summary: 'Get aggregated scambaiting stats for all scam types',
    tags: ['Scambaiting'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Aggregated statistics by scam type',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'scam_type_code', type: 'string'),
                                new OA\Property(property: 'total_sessions', type: 'integer'),
                                new OA\Property(property: 'avg_reward', type: 'number', format: 'float'),
                            ]
                        )
                    ),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/stats', name: 'api_scambaiting_all_stats', methods: ['GET'])]
#[IsGranted('monitoring:read')]
final class GetAllScambaitingStatsController extends AbstractController
{
    public function __construct(
        private readonly ScambaitingStatsQueryService $statsQueryService
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $aggregatedStats = $this->statsQueryService->getAggregatedStatsByScamType();

        return new JsonResponse([
            'success' => true,
            'data' => $aggregatedStats,
        ], Response::HTTP_OK);
    }
}
