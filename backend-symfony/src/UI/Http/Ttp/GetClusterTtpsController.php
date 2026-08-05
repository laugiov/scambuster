<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/{id}/ttps',
    summary: 'Aggregated TTP profile for a threat-actor cluster',
    description: 'Per-TTP frequencies, first/last seen and top adjacent-pair sequences across the cluster conversations. Confirmed observations only; no evidence text is ever included.',
    tags: ['Clusters', 'TTPs'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Cluster TTP profile (empty lists when the cluster has no observations)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'cluster_id', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                                new OA\Property(property: 'observation_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                                new OA\Property(property: 'avg_confidence', type: 'number', format: 'float'),
                                new OA\Property(property: 'first_seen', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'last_seen', type: 'string', format: 'date-time', nullable: true),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'top_sequences',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'sequence', type: 'array', items: new OA\Items(type: 'string')),
                                new OA\Property(property: 'count', type: 'integer'),
                            ]
                        )
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Cluster not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetClusterTtpsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/clusters/{id}/ttps', name: 'cluster_ttps', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        $profile = $this->queryService->clusterTtpProfile($id);

        if ($profile === null) {
            return new JsonResponse(['error' => 'Cluster not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($profile);
    }
}
