<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/phase-trend',
    summary: 'Weekly confirmed-observation counts per scam phase',
    description: 'Confirmed TTP observations bucketed by ISO week of the message timestamp over the last 8 weeks (current week included). Every week and every canonical phase is zero-filled server-side, so the grid is always dense. No evidence text is ever included.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Dense weekly phase counts (all zeros when nothing is observed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'weeks',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'week', type: 'string', format: 'date', example: '2026-06-01'),
                                new OA\Property(
                                    property: 'counts',
                                    type: 'object',
                                    additionalProperties: new OA\AdditionalProperties(type: 'integer'),
                                    example: ['hook' => 3, 'trust-building' => 1]
                                ),
                            ]
                        )
                    ),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpPhaseTrendController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/phase-trend', name: 'ttps_phase_trend', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->phaseTrend());
    }
}
