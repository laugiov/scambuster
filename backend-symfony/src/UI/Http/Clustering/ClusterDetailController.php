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
    path: '/api/v1/clusters/{id}',
    summary: 'Cluster detail with conversations and anchor IOCs',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Cluster detail'),
        new OA\Response(response: 404, description: 'Cluster not found'),
    ],
    security: [['Bearer' => []]],
)]
final readonly class ClusterDetailController
{
    public function __construct(
        private ClusterQueryService $queryService,
    ) {
    }
    #[Route('/api/v1/clusters/{id}', name: 'cluster_detail', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        $detail = $this->queryService->getDetail($id);

        if ($detail === null) {
            return new JsonResponse(['error' => 'Cluster not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($detail);
    }
}
