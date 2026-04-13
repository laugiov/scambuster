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
    path: '/api/v1/monitoring/analytics/scam-distribution',
    summary: 'Conversation count by scam type',
    tags: ['Analytics'],
    responses: [new OA\Response(response: 200, description: 'Scam type distribution data')],
    security: [['Bearer' => []]],
)]
final class ScamDistributionController
{
    public function __construct(
        private readonly AnalyticsHandler $handler,
    ) {
    }

    #[Route('/api/v1/monitoring/analytics/scam-distribution', name: 'api_analytics_scam_distribution', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getScamDistribution(), Response::HTTP_OK);
    }
}
