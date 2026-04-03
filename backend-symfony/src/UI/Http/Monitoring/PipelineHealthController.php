<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\PipelineTraceHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/monitoring/pipeline-health', name: 'api_monitoring_pipeline_health', methods: ['GET'])]
#[IsGranted('monitoring:read')]
final class PipelineHealthController
{
    public function __construct(
        private readonly PipelineTraceHandler $handler,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/monitoring/pipeline-health',
        summary: 'Get aggregated pipeline health metrics',
        tags: ['Monitoring'],
        parameters: [
            new OA\Parameter(name: 'hours', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 24)),
        ],
        responses: [new OA\Response(response: 200, description: 'Pipeline health metrics')],
        security: [['Bearer' => []]],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $hours = (int) $request->query->get('hours', '24');

        return new JsonResponse(
            $this->handler->getHealthMetrics($hours),
            Response::HTTP_OK,
        );
    }
}
