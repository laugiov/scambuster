<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Returns persona selection statistics for a given scam_type.
 * Utile pour monitoring et debugging.
 */
#[OA\Get(
    path: '/api/v1/scambaiting/stats/{scamTypeCode}',
    summary: 'Get persona selection stats for a specific scam type',
    tags: ['Scambaiting'],
    parameters: [
        new OA\Parameter(name: 'scamTypeCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'PHISHING')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Selection statistics for the scam type',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'scam_type_code', type: 'string'),
                            new OA\Property(property: 'total_personas', type: 'integer'),
                            new OA\Property(property: 'cold_start_count', type: 'integer'),
                            new OA\Property(property: 'epsilon', type: 'number', format: 'float'),
                            new OA\Property(property: 'cold_start_threshold', type: 'integer'),
                            new OA\Property(property: 'converged', type: 'boolean'),
                            new OA\Property(property: 'convergence_threshold', type: 'number', format: 'float'),
                            new OA\Property(
                                property: 'best_persona',
                                type: 'object',
                                nullable: true,
                                properties: [
                                    new OA\Property(property: 'persona_code', type: 'string'),
                                    new OA\Property(property: 'reward_avg', type: 'number', format: 'float'),
                                    new OA\Property(property: 'sessions_count', type: 'integer'),
                                ]
                            ),
                            new OA\Property(
                                property: 'top_5',
                                type: 'array',
                                items: new OA\Items(
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'persona_code', type: 'string'),
                                        new OA\Property(property: 'reward_avg', type: 'number', format: 'float'),
                                        new OA\Property(property: 'sessions_count', type: 'integer'),
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
            description: 'Scam type not found',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'error', type: 'string'),
                    new OA\Property(property: 'scam_type_code', type: 'string'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/stats/{scamTypeCode}', name: 'api_scambaiting_stats', methods: ['GET'])]
#[IsGranted('monitoring:read')]
final class GetScambaitingStatsController extends AbstractController
{
    public function __construct(
        private readonly PersonaOptimizer $personaOptimizer
    ) {
    }

    public function __invoke(string $scamTypeCode): JsonResponse
    {
        $stats = $this->personaOptimizer->getSelectionStats($scamTypeCode);

        if (isset($stats['error'])) {
            return new JsonResponse($stats, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $stats,
        ], Response::HTTP_OK);
    }
}
