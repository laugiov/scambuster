<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/{code}/conversations',
    summary: 'Conversations in which a TTP was observed',
    description: 'Server-paginated conversations carrying observations of this TTP, most recent observation first. Each row reports the confirmed/review count split, the conversation subject (first message, nullable), the scam type and the newest message carrying the TTP. No evidence text is ever included.',
    tags: ['TTPs', 'Conversations'],
    parameters: [
        new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'SB-T017')),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, minimum: 1, maximum: 100)),
        new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0, minimum: 0)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Paginated conversations (empty list when none)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'subject', type: 'string', nullable: true),
                                new OA\Property(property: 'scam_type_code', type: 'string', nullable: true, example: 'ADVANCE_FEE'),
                                new OA\Property(property: 'observation_count', type: 'integer'),
                                new OA\Property(property: 'review_count', type: 'integer'),
                                new OA\Property(property: 'last_seen', type: 'string', format: 'date-time', nullable: true),
                            ]
                        )
                    ),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'limit', type: 'integer'),
                    new OA\Property(property: 'offset', type: 'integer'),
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
final readonly class GetTtpConversationsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/{code}/conversations', name: 'ttp_conversations', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9-]+'])]
    #[IsGranted('ioc:read')]
    public function __invoke(string $code, Request $request): JsonResponse
    {
        if (!$this->queryService->ttpExists($code)) {
            return new JsonResponse(['error' => 'TTP not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = (int) $request->query->get('limit', (string) TtpQueryService::CONVERSATIONS_PAGE_DEFAULT);
        $limit = max(1, min($limit, TtpQueryService::CONVERSATIONS_PAGE_MAX));
        $offset = max(0, (int) $request->query->get('offset', '0'));

        return new JsonResponse([
            'items' => $this->queryService->conversationsForTtp($code, $limit, $offset),
            'total' => $this->queryService->countConversationsForTtp($code),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}
