<?php

declare(strict_types=1);

namespace App\UI\Http\Clustering;

use App\Application\Clustering\ClusterQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/clusters/stats',
    summary: 'Global clustering statistics',
    tags: ['Clusters'],
    parameters: [
        new OA\Parameter(
            name: 'scam_type',
            in: 'query',
            required: false,
            description: 'Spec 096 / C4 — optional scam type filter (cluster.primary_scam_types ANY match)',
            schema: new OA\Schema(type: 'string'),
        ),
        new OA\Parameter(
            name: 'period',
            in: 'query',
            required: false,
            description: 'Spec 096 / C5 — period restricts conversation counts (not cluster counts)',
            schema: new OA\Schema(type: 'string', default: 'all', enum: ['7d', '30d', '90d', 'all']),
        ),
    ],
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
    public function __invoke(Request $request): JsonResponse
    {
        // Spec 096 / C4 — optional scam_type filter.
        $scamTypeRaw = $request->query->get('scam_type');
        $scamType = \is_string($scamTypeRaw) && trim($scamTypeRaw) !== '' ? trim($scamTypeRaw) : null;

        // Spec 096 / C5 — optional period filter, applied to conversation counts.
        $period = $request->query->getString('period', 'all');

        return new JsonResponse($this->queryService->getStats($scamType, $period));
    }
}
