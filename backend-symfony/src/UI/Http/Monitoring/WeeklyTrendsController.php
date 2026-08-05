<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AnalyticsHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/monitoring/analytics/weekly-trends',
    summary: 'Current week vs previous week trend deltas',
    tags: ['Analytics'],
    responses: [new OA\Response(response: 200, description: 'Weekly trend data')],
    security: [['Bearer' => []]],
)]
final readonly class WeeklyTrendsController
{
    public function __construct(
        private AnalyticsHandler $handler,
    ) {
    }
    #[Route('/api/v1/monitoring/analytics/weekly-trends', name: 'api_analytics_weekly_trends', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getWeeklyTrends(), Response::HTTP_OK);
    }
}
