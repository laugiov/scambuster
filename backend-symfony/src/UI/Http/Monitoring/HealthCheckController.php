<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\HealthCheckHandler;
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
    public function __invoke(): JsonResponse
    {
        $result = $this->handler->check();
        $status = $result['status'] === 'ok'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($result, $status);
    }
}
