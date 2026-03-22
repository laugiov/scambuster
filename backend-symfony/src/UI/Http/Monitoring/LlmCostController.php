<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\LlmCostHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LLM cost monitoring endpoint.
 *
 * Returns current month costs, per-purpose breakdown,
 * daily trend, and limit status.
 * Auth handled by Symfony firewall (same as /monitoring/autonomy).
 */
final class LlmCostController
{
    public function __construct(
        private readonly LlmCostHandler $handler
    ) {
    }

    #[Route('/api/v1/monitoring/llm-cost', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getCostReport());
    }
}
