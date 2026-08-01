<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Stix\ClusteredThreatActorStixBuilder;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[OA\Get(
    path: '/api/v1/clusters/{id}/export/stix',
    summary: 'STIX 2.1 bundle for this cluster',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'STIX bundle'),
        new OA\Response(response: 404, description: 'Cluster not found'),
    ],
    security: [['Bearer' => []]],
)]
final readonly class ExportClusterStixController
{
    public function __construct(
        private ClusterQueryService $queryService,
    ) {
    }
    #[Route('/api/v1/clusters/{id}/export/stix', name: 'cluster_export_stix', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        $exportData = $this->queryService->getStixExportData($id);

        if ($exportData === null) {
            return new JsonResponse(['error' => 'Cluster not found'], Response::HTTP_NOT_FOUND);
        }

        $builder = new ClusteredThreatActorStixBuilder();
        $objects = $builder->buildBundle($exportData);

        $bundle = [
            'type' => 'bundle',
            'id' => 'bundle--' . Uuid::v4()->toRfc4122(),
            'objects' => $objects,
        ];

        return new JsonResponse($bundle, Response::HTTP_OK, [
            'Content-Type' => 'application/stix+json;version=2.1',
        ]);
    }
}
