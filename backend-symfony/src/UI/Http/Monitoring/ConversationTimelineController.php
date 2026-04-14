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
    path: '/api/v1/monitoring/analytics/conversation-timeline',
    summary: 'Conversations opened and closed per day',
    tags: ['Analytics'],
    parameters: [
        new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
    ],
    responses: [new OA\Response(response: 200, description: 'Conversation timeline data')],
    security: [['Bearer' => []]],
)]
final readonly class ConversationTimelineController
{
    public function __construct(
        private AnalyticsHandler $handler,
    ) {
    }
    #[Route('/api/v1/monitoring/analytics/conversation-timeline', name: 'api_analytics_conversation_timeline', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return new JsonResponse($this->handler->getConversationTimeline($days), Response::HTTP_OK);
    }
}
