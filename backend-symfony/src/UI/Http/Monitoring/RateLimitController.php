<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\RateLimitHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/rate-limits', name: 'monitoring_rate_limits', methods: ['GET'])]
final class RateLimitController
{
    public function __invoke(RateLimitHandler $handler): JsonResponse
    {
        return new JsonResponse($handler->getStats());
    }
}
