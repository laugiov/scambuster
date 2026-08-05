<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaMatrixQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Persona × scam type matrix endpoint.
 *
 * Single read-only call returning every (active persona, active scam
 * type) pair with the aggregated reward + session count. Consumed by
 * the new PersonaMatrix page.
 */
#[OA\Get(
    path: '/api/v1/scambaiting/persona-matrix',
    summary: 'Persona x scam type performance matrix (all active pairs)',
    tags: ['Scambaiting'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Matrix data',
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
                                new OA\Property(property: 'persona_code', type: 'string'),
                                new OA\Property(property: 'persona_label', type: 'string'),
                                new OA\Property(property: 'scam_type_code', type: 'string'),
                                new OA\Property(property: 'scam_type_label', type: 'string'),
                                new OA\Property(property: 'sessions', type: 'integer'),
                                new OA\Property(property: 'reward_avg', type: 'number', format: 'float', nullable: true),
                            ]
                        )
                    ),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/persona-matrix', name: 'api_scambaiting_persona_matrix', methods: ['GET'])]
#[IsGranted('monitoring:read')]
final readonly class GetPersonaMatrixController
{
    public function __construct(
        private PersonaMatrixQueryService $service,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->service->getMatrix(),
        ], Response::HTTP_OK);
    }
}
