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
    path: '/api/v1/monitoring/analytics/ioc-timeline',
    summary: 'IOC extraction count per day',
    tags: ['Analytics'],
    parameters: [
        new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
    ],
    responses: [new OA\Response(response: 200, description: 'IOC timeline data')],
    security: [['Bearer' => []]],
)]
final class IocTimelineController
{
    public function __construct(
        private readonly AnalyticsHandler $handler,
    ) {
    }

    #[Route('/api/v1/monitoring/analytics/ioc-timeline', name: 'api_analytics_ioc_timeline', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return new JsonResponse($this->handler->getIocTimeline($days), Response::HTTP_OK);
    }
}
