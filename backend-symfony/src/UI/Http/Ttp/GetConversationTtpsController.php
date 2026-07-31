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
    path: '/api/v1/conversations/{id}/ttps',
    summary: 'TTP observations and elicitation timeline for a conversation',
    description: 'Ordered TTP observations plus a per-message timeline interleaving TTPs, revealed IOCs and the stimulus attributed to each outbound message. Responses carry evidence offsets only, never evidence text: quotes are reconstructed client-side from the message bodies.',
    tags: ['Conversations', 'TTPs'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(
            response: 200,
            description: 'TTP observations and timeline',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'observations',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                                new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                                new OA\Property(property: 'status', type: 'string', enum: ['confirmed', 'review']),
                                new OA\Property(property: 'evidence_start', type: 'integer', nullable: true),
                                new OA\Property(property: 'evidence_end', type: 'integer', nullable: true),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'timeline',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
                                new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time'),
                                new OA\Property(property: 'subject', type: 'string', nullable: true),
                                new OA\Property(property: 'ttps', type: 'array', items: new OA\Items(type: 'object')),
                                new OA\Property(
                                    property: 'iocs_revealed',
                                    description: 'IOCs revealed in the message; non-actionable types (header noise such as spf/dkim results) are filtered out server-side.',
                                    type: 'array',
                                    items: new OA\Items(
                                        properties: [
                                            new OA\Property(property: 'type', type: 'string'),
                                            new OA\Property(property: 'value_norm', type: 'string'),
                                            new OA\Property(property: 'indicator_id', type: 'string', format: 'uuid', nullable: true),
                                            new OA\Property(property: 'stimulus_msg_id', type: 'string', format: 'uuid', nullable: true),
                                        ],
                                        type: 'object'
                                    )
                                ),
                                new OA\Property(property: 'stimulus_type', type: 'string', nullable: true),
                            ]
                        )
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetConversationTtpsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/conversations/{id}/ttps', name: 'conversation_ttps', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $id): JsonResponse
    {
        if (!$this->queryService->conversationExists($id)) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        $profile = $this->queryService->conversationTtps($id);

        return new JsonResponse([
            'conv_id' => $profile['conv_id'],
            'observations' => $profile['observations'],
            'timeline' => $this->queryService->conversationTimeline($id),
        ]);
    }
}
