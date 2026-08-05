<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/cluster-matrix',
    summary: 'Shared-playbook matrix across threat-actor clusters',
    description: 'Sparse cluster x TTP grid of confirmed observation counts across every live (non-merged) cluster with at least one confirmed observation. Each cluster row also carries its conversation_total (distinct conversations with any confirmed observation) and each cell its conversation_count (distinct conversations exhibiting the TTP), so the consumer can normalize per conversation. Zero cells are omitted; only observed TTP columns are returned. The cluster set is capped and a truncated flag plus the full total_clusters are reported when the cap bites. No evidence text is ever included.',
    tags: ['TTPs', 'Clusters'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Shared-playbook matrix (empty clusters/cells when nothing is observed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'clusters',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'cluster_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'observation_total', type: 'integer'),
                                new OA\Property(property: 'conversation_total', type: 'integer', description: 'Distinct conversations with any confirmed observation (the per-conversation normalizer).'),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
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
                                new OA\Property(property: 'cluster_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'count', type: 'integer', description: 'Confirmed observation count for this (cluster, TTP) pair.'),
                                new OA\Property(property: 'conversation_count', type: 'integer', description: 'Distinct conversations in the cluster exhibiting this TTP.'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'truncated', type: 'boolean'),
                    new OA\Property(property: 'total_clusters', type: 'integer'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetClusterTtpMatrixController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/cluster-matrix', name: 'ttps_cluster_matrix', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->clusterTtpMatrix());
    }
}
