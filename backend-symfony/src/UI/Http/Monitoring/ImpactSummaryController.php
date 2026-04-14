<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\ImpactHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/impact/summary',
    summary: 'Impact summary: wasted time, IOC value, cost efficiency, campaigns',
    tags: ['Impact'],
    parameters: [
        new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'all', enum: ['7d', '30d', '90d', 'all'])),
    ],
    responses: [new OA\Response(response: 200, description: 'Impact summary data')],
    security: [['Bearer' => []]],
)]
final readonly class ImpactSummaryController
{
    public function __construct(
        private ImpactHandler $handler,
    ) {
    }
    #[Route('/api/v1/impact/summary', name: 'api_impact_summary', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->query->getString('period', 'all');

        return new JsonResponse($this->handler->getSummary($period), Response::HTTP_OK);
    }
}
