<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters',
    summary: 'List active threat-actor clusters',
    tags: ['Clusters'],
    responses: [new OA\Response(response: 200, description: 'Cluster list')],
    security: [['Bearer' => []]],
)]
final readonly class ListClustersController
{
    public function __construct(
        private ClusterQueryService $queryService,
    ) {
    }
    #[Route('/api/v1/clusters', name: 'cluster_list', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->listClusters());
    }
}
