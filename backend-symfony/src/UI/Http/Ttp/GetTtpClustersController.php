<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/{code}/clusters',
    summary: 'Threat-actor clusters practicing a TTP',
    description: 'Live (non-merged) clusters whose conversations carry confirmed observations of this TTP, with observation/conversation counts and first/last seen, widest conversation span first. The list is capped and a truncated flag is reported when the cap bites. No evidence text is ever included.',
    tags: ['TTPs', 'Clusters'],
    parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'SB-T017'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Clusters practicing the TTP (empty list when none)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'cluster_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'observation_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                                new OA\Property(property: 'first_seen', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'last_seen', type: 'string', format: 'date-time', nullable: true),
                            ]
                        )
                    ),
                    new OA\Property(property: 'truncated', type: 'boolean'),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'TTP not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpClustersController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/{code}/clusters', name: 'ttp_clusters', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $code): JsonResponse
    {
        if (!$this->queryService->ttpExists($code)) {
            return new JsonResponse(['error' => 'TTP not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->queryService->clustersForTtp($code));
    }
}
