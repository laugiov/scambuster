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
    path: '/api/v1/ttps/{code}/iocs',
    summary: 'IOCs co-observed with a TTP',
    description: 'IOCs revealed in the same messages as this TTP (confirmed observations only), with per-IOC co-occurrence and conversation counts. No evidence text is ever included.',
    tags: ['TTPs', 'IOCs'],
    parameters: [new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'SB-T017'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Co-occurring IOCs (empty list when none)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                    new OA\Property(
                        property: 'iocs',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'indicator_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'type', type: 'string'),
                                new OA\Property(property: 'value_norm', type: 'string'),
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
            description: 'TTP not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpIocsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/{code}/iocs', name: 'ttp_iocs', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $code): JsonResponse
    {
        if (!$this->queryService->ttpExists($code)) {
            return new JsonResponse(['error' => 'TTP not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->queryService->iocsForTtp($code));
    }
}
