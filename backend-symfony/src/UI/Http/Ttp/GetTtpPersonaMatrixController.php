<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/persona-matrix',
    summary: 'Persona x TTP matrix (confirmed observations)',
    description: 'Sparse persona x TTP grid of confirmed observation counts. The join path is ttp_observation.conv_id -> conversation.persona_id -> persona; conversations with no persona are excluded from the grid and reported in null_persona_conversations. Each cell carries the raw observation_count and the fair conversation_count (distinct conversations exhibiting the TTP); each persona row carries its conversation_total denominator. The persona set is capped (widest conversation volume first) with an explicit truncated flag and the full total_personas. No evidence text is ever included.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Persona x TTP matrix (empty personas/cells when nothing is observed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'personas',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'code', type: 'string', example: 'elderly_person'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'conversation_total', type: 'integer'),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'cells',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'persona_code', type: 'string', example: 'elderly_person'),
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'observation_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'truncated', type: 'boolean'),
                    new OA\Property(property: 'total_personas', type: 'integer'),
                    new OA\Property(property: 'null_persona_conversations', type: 'integer'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpPersonaMatrixController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/persona-matrix', name: 'ttps_persona_matrix', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->personaMatrix());
    }
}
