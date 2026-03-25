<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\RateLimitHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/rate-limits', name: 'monitoring_rate_limits', methods: ['GET'])]
final class RateLimitController
{
    #[OA\Get(
        path: '/api/v1/monitoring/rate-limits',
        summary: 'Rate limit status and today\'s violations',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rate limit configuration and violation counts',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'llm_calls_limit', type: 'integer', example: 200),
                        new OA\Property(property: 'active_conversations_limit', type: 'integer', example: 50),
                        new OA\Property(
                            property: 'rate_limited_today',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'type', type: 'string', example: 'sender_flood'),
                                    new OA\Property(property: 'count', type: 'integer', example: 3),
                                ]
                            )
                        ),
                        new OA\Property(property: 'quarantined_senders_today', type: 'integer', example: 2),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(RateLimitHandler $handler): JsonResponse
    {
        return new JsonResponse($handler->getStats());
    }
}
