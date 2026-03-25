<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\LlmCostHandler;
use OpenApi\Attributes as OA;
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
    #[OA\Get(
        path: '/api/v1/monitoring/llm-cost',
        summary: 'LLM cost monitoring report',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current month LLM cost report',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'current_month',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total_usd', type: 'number', format: 'float', example: 1.234),
                                new OA\Property(property: 'limit_usd', type: 'number', format: 'float', example: 10.0),
                                new OA\Property(property: 'pct_used', type: 'number', format: 'float', example: 12.3),
                                new OA\Property(property: 'calls_count', type: 'integer', example: 42),
                                new OA\Property(property: 'total_prompt_tokens', type: 'integer', example: 50000),
                                new OA\Property(property: 'total_completion_tokens', type: 'integer', example: 12000),
                            ]
                        ),
                        new OA\Property(property: 'per_purpose', type: 'object'),
                        new OA\Property(
                            property: 'daily_trend',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'date', type: 'string', format: 'date'),
                                    new OA\Property(property: 'cost_usd', type: 'number', format: 'float'),
                                    new OA\Property(property: 'calls', type: 'integer'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'limit_exceeded', type: 'boolean'),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getCostReport());
    }
}
