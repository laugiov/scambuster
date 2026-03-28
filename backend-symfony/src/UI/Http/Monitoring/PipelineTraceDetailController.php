<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\PipelineTraceHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/pipeline-traces/{msgId}', name: 'api_monitoring_pipeline_trace_detail', methods: ['GET'])]
final class PipelineTraceDetailController
{
    public function __construct(
        private readonly PipelineTraceHandler $handler,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/monitoring/pipeline-traces/{msgId}',
        summary: 'Get full pipeline trace for a specific message',
        tags: ['Monitoring'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Full pipeline trace'),
            new OA\Response(response: 404, description: 'Message not found or has no trace'),
        ],
        security: [['Bearer' => []]],
    )]
    public function __invoke(string $msgId): JsonResponse
    {
        $trace = $this->handler->getTraceByMessageId($msgId);

        if ($trace === null) {
            return new JsonResponse(['error' => 'Trace not found for message ' . $msgId], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($trace, Response::HTTP_OK);
    }
}
