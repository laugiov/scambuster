<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/iocs/{indicatorId}/cluster',
    summary: 'Find cluster for an IOC indicator',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'indicatorId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Cluster data'),
        new OA\Response(response: 404, description: 'No cluster (singleton IOC)'),
    ],
    security: [['Bearer' => []]],
)]
final class IocClusterLookupController
{
    public function __construct(
        private readonly ClusterQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/iocs/{indicatorId}/cluster', name: 'ioc_cluster_lookup', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $indicatorId): JsonResponse
    {
        $cluster = $this->queryService->getClusterForIndicator($indicatorId);

        if ($cluster === null) {
            return new JsonResponse(['error' => 'No cluster for this IOC'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($cluster);
    }
}
