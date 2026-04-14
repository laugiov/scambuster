<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/stats',
    summary: 'Global clustering statistics',
    tags: ['Clusters'],
    responses: [new OA\Response(response: 200, description: 'Clustering stats')],
    security: [['Bearer' => []]],
)]
final readonly class ClusterStatsController
{
    public function __construct(
        private ClusterQueryService $queryService,
    ) {
    }
    #[Route('/api/v1/clusters/stats', name: 'cluster_stats', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->getStats());
    }
}
