<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterTemporalAnalyzer;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/{id}/temporal',
    summary: 'Temporal / burst / cadence analysis for a threat-actor cluster',
    description: 'Activity window, hour-of-day and day-of-week cadence, busiest day, burst days (>= 2x median daily volume) and longest dormancy, computed from the cluster inbound messages.',
    tags: ['Clusters'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Temporal metrics'),
        new OA\Response(response: 404, description: 'No inbound activity for this cluster'),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetClusterTemporalController
{
    public function __construct(
        private ClusterTemporalAnalyzer $analyzer,
    ) {
    }

    #[Route('/api/v1/clusters/{id}/temporal', name: 'cluster_temporal', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        $metrics = $this->analyzer->analyze($id);

        if ($metrics === null) {
            return new JsonResponse(['error' => 'No temporal activity for this cluster'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($metrics);
    }
}
