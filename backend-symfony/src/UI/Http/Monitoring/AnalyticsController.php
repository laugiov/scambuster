<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AnalyticsHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/monitoring/analytics')]
#[IsGranted('monitoring:read')]
#[OA\Tag(name: 'Analytics')]
final class AnalyticsController
{
    private const CACHE_MAX_AGE = 60; // 1 minute

    public function __construct(
        private readonly AnalyticsHandler $handler,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    private function cachedJson(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->setMaxAge(self::CACHE_MAX_AGE);
        $response->setPublic();

        return $response;
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/ioc-timeline',
        summary: 'IOC extraction count per day',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [new OA\Response(response: 200, description: 'IOC timeline data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/ioc-timeline', name: 'api_analytics_ioc_timeline', methods: ['GET'])]
    public function iocTimeline(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return $this->cachedJson($this->handler->getIocTimeline($days));
    }

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
    #[Route('/conversation-timeline', name: 'api_analytics_conversation_timeline', methods: ['GET'])]
    public function conversationTimeline(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return $this->cachedJson($this->handler->getConversationTimeline($days));
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/ioc-distribution',
        summary: 'IOC count by type',
        tags: ['Analytics'],
        responses: [new OA\Response(response: 200, description: 'IOC distribution data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/ioc-distribution', name: 'api_analytics_ioc_distribution', methods: ['GET'])]
    public function iocDistribution(): JsonResponse
    {
        return $this->cachedJson($this->handler->getIocDistribution());
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/scam-distribution',
        summary: 'Conversation count by scam type',
        tags: ['Analytics'],
        responses: [new OA\Response(response: 200, description: 'Scam type distribution data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/scam-distribution', name: 'api_analytics_scam_distribution', methods: ['GET'])]
    public function scamDistribution(): JsonResponse
    {
        return $this->cachedJson($this->handler->getScamDistribution());
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/cost-timeline',
        summary: 'Daily LLM cost from pipeline traces',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [new OA\Response(response: 200, description: 'Cost timeline data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/cost-timeline', name: 'api_analytics_cost_timeline', methods: ['GET'])]
    public function costTimeline(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return $this->cachedJson($this->handler->getCostTimeline($days));
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/pipeline-timeline',
        summary: 'Pipeline reply outcomes per day',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [new OA\Response(response: 200, description: 'Pipeline timeline data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/pipeline-timeline', name: 'api_analytics_pipeline_timeline', methods: ['GET'])]
    public function pipelineTimeline(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '30');

        return $this->cachedJson($this->handler->getPipelineTimeline($days));
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/activity-feed',
        summary: 'Recent platform activity feed',
        tags: ['Analytics'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Activity feed events')],
        security: [['Bearer' => []]],
    )]
    #[Route('/activity-feed', name: 'api_analytics_activity_feed', methods: ['GET'])]
    public function activityFeed(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', '10');

        return $this->cachedJson($this->handler->getActivityFeed($limit));
    }

    #[OA\Get(
        path: '/api/v1/monitoring/analytics/weekly-trends',
        summary: 'Current week vs previous week trend deltas',
        tags: ['Analytics'],
        responses: [new OA\Response(response: 200, description: 'Weekly trend data')],
        security: [['Bearer' => []]],
    )]
    #[Route('/weekly-trends', name: 'api_analytics_weekly_trends', methods: ['GET'])]
    public function weeklyTrends(): JsonResponse
    {
        return $this->cachedJson($this->handler->getWeeklyTrends());
    }
}
