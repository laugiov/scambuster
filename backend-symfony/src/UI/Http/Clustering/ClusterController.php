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

#[Route('/api/v1')]
#[IsGranted('ioc:read')]
final class ClusterController
{
    public function __construct(
        private readonly ClusterQueryService $queryService,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/clusters',
        summary: 'List active threat-actor clusters',
        tags: ['Clusters'],
        responses: [new OA\Response(response: 200, description: 'Cluster list')],
        security: [['Bearer' => []]]
    )]
    #[Route('/clusters', name: 'cluster_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse($this->queryService->listClusters());
    }

    #[OA\Get(
        path: '/api/v1/clusters/stats',
        summary: 'Global clustering statistics',
        tags: ['Clusters'],
        responses: [new OA\Response(response: 200, description: 'Clustering stats')],
        security: [['Bearer' => []]]
    )]
    #[Route('/clusters/stats', name: 'cluster_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return new JsonResponse($this->queryService->getStats());
    }

    #[OA\Get(
        path: '/api/v1/clusters/{id}',
        summary: 'Cluster detail with conversations and anchor IOCs',
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Cluster detail'),
            new OA\Response(response: 404, description: 'Cluster not found'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/clusters/{id}', name: 'cluster_detail', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function detail(string $id): JsonResponse
    {
        $detail = $this->queryService->getDetail($id);

        if ($detail === null) {
            return new JsonResponse(['error' => 'Cluster not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($detail);
    }

    #[OA\Get(
        path: '/api/v1/clusters/{id}/export/stix',
        summary: 'STIX 2.1 bundle for this cluster',
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'STIX bundle'),
            new OA\Response(response: 404, description: 'Cluster not found'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/clusters/{id}/export/stix', name: 'cluster_export_stix', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function exportStix(string $id): JsonResponse
    {
        $exportData = $this->queryService->getStixExportData($id);

        if ($exportData === null) {
            return new JsonResponse(['error' => 'Cluster not found'], Response::HTTP_NOT_FOUND);
        }

        $builder = new ClusteredThreatActorStixBuilder();
        $objects = $builder->buildBundle($exportData);

        $bundle = [
            'type' => 'bundle',
            'id' => 'bundle--' . \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'objects' => $objects,
        ];

        return new JsonResponse($bundle, Response::HTTP_OK, [
            'Content-Type' => 'application/stix+json;version=2.1',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/iocs/{indicatorId}/cluster',
        summary: 'Find cluster for an IOC indicator',
        tags: ['Clusters'],
        parameters: [new OA\Parameter(name: 'indicatorId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Cluster data'),
            new OA\Response(response: 404, description: 'No cluster (singleton IOC)'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/iocs/{indicatorId}/cluster', name: 'ioc_cluster_lookup', methods: ['GET'])]
    public function iocCluster(string $indicatorId): JsonResponse
    {
        $cluster = $this->queryService->getClusterForIndicator($indicatorId);

        if ($cluster === null) {
            return new JsonResponse(['error' => 'No cluster for this IOC'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($cluster);
    }
}
