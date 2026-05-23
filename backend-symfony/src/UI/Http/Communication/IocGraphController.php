<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for IOC co-occurrence graph data.
 */
#[IsGranted('ioc:read')]
final readonly class IocGraphController
{
    public function __construct(
        private IocHandler $handler
    ) {
    }
    #[OA\Get(
        path: '/api/v1/iocs/co-occurrence',
        summary: 'Get IOC co-occurrence graph (nodes + edges)',
        tags: ['IOCs'],
        parameters: [
            new OA\Parameter(
                name: 'indicator_id',
                in: 'query',
                required: true,
                description: 'Center indicator UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'max_nodes',
                in: 'query',
                required: false,
                description: 'Maximum related nodes (default 30)',
                schema: new OA\Schema(type: 'integer', default: 30)
            )
        ],
        responses: [
            new OA\Response(response: 200, description: 'Graph data with nodes and edges'),
            new OA\Response(response: 400, description: 'Missing indicator_id parameter')
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/iocs/co-occurrence', name: 'ioc_co_occurrence', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $indicatorId = $request->query->get('indicator_id');

        // Spec 090 — Symfony 7.4 tightened Request::query->get() return type;
        // is_string() check is now redundant per static analysis.
        if (!$indicatorId) {
            return new JsonResponse(['error' => 'Missing required parameter: indicator_id'], Response::HTTP_BAD_REQUEST);
        }

        $maxNodesParam = $request->query->get('max_nodes');
        $maxNodes = is_numeric($maxNodesParam) ? min((int) $maxNodesParam, 50) : 30;

        $graph = $this->handler->getCoOccurrenceGraph($indicatorId, $maxNodes);

        return new JsonResponse($graph, Response::HTTP_OK);
    }
}
