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
    path: '/api/v1/monitoring/analytics/activity-feed',
    summary: 'Recent platform activity feed',
    tags: ['Analytics'],
    parameters: [
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
    ],
    responses: [new OA\Response(response: 200, description: 'Activity feed events')],
    security: [['Bearer' => []]],
)]
final readonly class ActivityFeedController
{
    public function __construct(
        private AnalyticsHandler $handler,
    ) {
    }
    #[Route('/api/v1/monitoring/analytics/activity-feed', name: 'api_analytics_activity_feed', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', '10');

        return new JsonResponse($this->handler->getActivityFeed($limit), Response::HTTP_OK);
    }
}
