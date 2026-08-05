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
    path: '/api/v1/iocs/{id}/ttps',
    summary: 'TTPs co-observed with an IOC',
    description: 'TTPs observed in the same messages as this IOC indicator (confirmed observations only), with per-TTP co-occurrence and conversation counts. No evidence text is ever included.',
    tags: ['IOCs', 'TTPs'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Co-occurring TTPs (empty list when none)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'ioc', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                                new OA\Property(property: 'co_occurrence_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                            ]
                        )
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Indicator not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetIocTtpsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/iocs/{id}/ttps', name: 'ioc_ttps', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $id): JsonResponse
    {
        if (!$this->queryService->indicatorExists($id)) {
            return new JsonResponse(['error' => 'Indicator not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->queryService->ttpsForIoc($id));
    }
}
