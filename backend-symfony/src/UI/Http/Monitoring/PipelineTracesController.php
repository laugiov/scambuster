<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\PipelineTraceHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/pipeline-traces', name: 'api_monitoring_pipeline_traces', methods: ['GET'])]
final class PipelineTracesController
{
    public function __construct(
        private readonly PipelineTraceHandler $handler,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/monitoring/pipeline-traces',
        summary: 'List recent pipeline execution traces',
        tags: ['Monitoring'],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 7)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
            new OA\Parameter(name: 'persona', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'scam_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Pipeline traces list')],
        security: [['Bearer' => []]],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', '7');
        $limit = (int) $request->query->get('limit', '50');
        $offset = (int) $request->query->get('offset', '0');
        $persona = $request->query->get('persona');
        $scamType = $request->query->get('scam_type');

        $result = $this->handler->getRecentTraces(
            $days,
            $limit,
            $offset,
            \is_string($persona) ? $persona : null,
            \is_string($scamType) ? $scamType : null,
        );

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
