<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaPerformanceHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Returns the performance of a persona across all scam_types.
 * Lets you analyze a persona's strengths and weaknesses.
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
#[IsGranted('monitoring:read')]
final readonly class GetPersonaPerformanceController
{
    public function __construct(
        private PersonaPerformanceHandler $handler,
    ) {
    }
    public function __invoke(string $personaCode): JsonResponse
    {
        try {
            $data = $this->handler->getPerformance($personaCode);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $data,
        ], Response::HTTP_OK);
    }
}
