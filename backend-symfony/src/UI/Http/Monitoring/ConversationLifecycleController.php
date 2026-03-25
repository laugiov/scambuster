<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\ConversationLifecycleHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conversation lifecycle monitoring endpoint.
 *
 * Returns active, about-to-timeout, completed today, by scam type.
 */
final class ConversationLifecycleController
{
    public function __construct(
        private readonly ConversationLifecycleHandler $handler
    ) {
    }

    #[Route('/api/v1/monitoring/conversation-lifecycle', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/monitoring/conversation-lifecycle',
        summary: 'Conversation lifecycle statistics',
        tags: ['Monitoring'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lifecycle stats with active, timeout, and per-scam-type breakdown',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'active', type: 'integer', example: 12),
                        new OA\Property(property: 'about_to_timeout', type: 'integer', example: 3),
                        new OA\Property(property: 'completed_today', type: 'integer', example: 5),
                        new OA\Property(property: 'reopened_today', type: 'integer', example: 1),
                        new OA\Property(property: 'by_scam_type', type: 'object'),
                        new OA\Property(
                            property: 'about_to_timeout_list',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'scam_type', type: 'string'),
                                    new OA\Property(property: 'persona', type: 'string'),
                                    new OA\Property(property: 'last_activity', type: 'string', format: 'date-time'),
                                    new OA\Property(property: 'timeout_hours', type: 'integer'),
                                    new OA\Property(property: 'hours_remaining', type: 'number', format: 'float'),
                                ]
                            )
                        ),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getLifecycleStats());
    }
}
