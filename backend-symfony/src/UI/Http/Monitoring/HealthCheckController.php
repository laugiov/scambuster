<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\HealthCheckHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Enhanced health check with dependency status.
 *
 * Unlike /healthz (simple liveness probe), this endpoint checks
 * database and Redis connectivity with latency measurements.
 */
final class HealthCheckController
{
    public function __construct(
        private readonly HealthCheckHandler $handler
    ) {
    }

    #[Route('/api/health', methods: ['GET'])]
    #[OA\Get(
        path: '/api/health',
        summary: 'Health check with dependency status',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'All dependencies healthy',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                        new OA\Property(property: 'version', type: 'string', example: '1.3.0'),
                        new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'uptime_seconds', type: 'integer'),
                        new OA\Property(
                            property: 'checks',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'database',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                                        new OA\Property(property: 'latency_ms', type: 'integer', example: 3),
                                    ]
                                ),
                                new OA\Property(
                                    property: 'redis',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'status', type: 'string', example: 'ok'),
                                        new OA\Property(property: 'latency_ms', type: 'integer', example: 1),
                                    ]
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 503,
                description: 'One or more dependencies unhealthy'
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(): JsonResponse
    {
        $result = $this->handler->check();
        $status = $result['status'] === 'ok'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($result, $status);
    }
}
