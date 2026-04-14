<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AnalyticsHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/monitoring/analytics/cost-timeline',
    summary: 'Daily LLM cost from pipeline traces',
    tags: ['Analytics'],
    parameters: [
        new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
    ],
    responses: [new OA\Response(response: 200, description: 'Cost timeline data')],
    security: [['Bearer' => []]],
)]
final readonly class CostTimelineController
{
    public function __construct(
        private AnalyticsHandler $handler,
    ) {
    }
    #[Route('/api/v1/monitoring/analytics/cost-timeline', name: 'api_analytics_cost_timeline', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return new JsonResponse($this->handler->getCostTimeline($days), Response::HTTP_OK);
    }
}
