<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AutonomyMonitoringHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Unified monitoring endpoint for autonomous operation status.
 */
#[Route('/api/v1/monitoring/autonomy', name: 'api_monitoring_autonomy', methods: ['GET'])]
final class AutonomyMonitoringController
{
    public function __construct(
        private readonly AutonomyMonitoringHandler $handler
    ) {
    }

    #[OA\Get(
        path: '/api/v1/monitoring/autonomy',
        summary: 'Autonomous operation monitoring dashboard',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Autonomy status',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'operational'),
                        new OA\Property(property: 'kill_switch_active', type: 'boolean'),
                        new OA\Property(property: 'conversations', type: 'object'),
                        new OA\Property(property: 'messages', type: 'object'),
                        new OA\Property(property: 'iocs', type: 'object'),
                        new OA\Property(property: 'convergence', type: 'object'),
                        new OA\Property(property: 'last_activity', type: 'object'),
                        new OA\Property(property: 'checked_at', type: 'string', format: 'date-time'),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getAutonomyStatus(), Response::HTTP_OK);
    }
}
