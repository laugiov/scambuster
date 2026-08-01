<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/phase-transitions',
    summary: 'Global kill-chain phase-transition aggregate',
    description: 'Cross-boundary TTP bigrams (confirmed observations only, message-timestamp axis, self-pairs excluded at the code level) aggregated by the kill-chain phase of each pair\'s endpoints, across every conversation. Zero transitions are omitted — the consumer renders the dense matrix; total_pairs reports the full bigram volume. No evidence text is ever included.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Sparse phase-transition cells (empty when nothing is observed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'transitions',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'from_phase', type: 'string', example: 'hook'),
                                new OA\Property(property: 'to_phase', type: 'string', example: 'trust-building'),
                                new OA\Property(property: 'count', type: 'integer'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'total_pairs', type: 'integer'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpPhaseTransitionsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/phase-transitions', name: 'ttps_phase_transitions', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->phaseTransitions());
    }
}
