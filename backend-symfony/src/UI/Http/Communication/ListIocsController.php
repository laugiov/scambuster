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
 * List all IOCs with confidence scoring.
 *
 * Returns all IOCs enriched with confidence, decay_factor, and effective_score.
 * Use ?min_score=0.5 to filter out low-confidence IOCs.
 */
#[OA\Get(
    path: '/api/v1/iocs',
    summary: 'List all IOCs with confidence scoring',
    tags: ['IOCs'],
    parameters: [
        new OA\Parameter(
            name: 'min_score',
            in: 'query',
            required: false,
            description: 'Minimum effective_score to include (0.0-1.0)',
            schema: new OA\Schema(type: 'number', format: 'float')
        )
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'List of IOCs with confidence data',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'obs_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'ioc_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'value', type: 'string'),
                    new OA\Property(property: 'value_norm', type: 'string'),
                    new OA\Property(property: 'score', type: 'object'),
                    new OA\Property(property: 'category', type: 'string'),
                    new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                    new OA\Property(property: 'decay_factor', type: 'number', format: 'float'),
                    new OA\Property(property: 'effective_score', type: 'number', format: 'float'),
                ])
            )
        )
    ],
    security: [['Bearer' => []]]
)]
#[IsGranted('ioc:read')]
final readonly class ListIocsController
{
    public function __construct(
        private IocHandler $handler
    ) {
    }
    #[Route('/api/v1/iocs', name: 'list_iocs', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $minScoreParam = $request->query->get('min_score');
        $minScore = $minScoreParam !== null ? (float) $minScoreParam : null;

        $iocs = $this->handler->getAllIocsWithConfidence($minScore);

        return new JsonResponse($iocs, Response::HTTP_OK);
    }
}
