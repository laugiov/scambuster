<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\GetCampaignMessagesHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaign_id}/messages', name: 'api_campaign_messages', methods: ['GET'])]
#[IsGranted('campaign:read')]
final readonly class GetCampaignMessagesController
{
    public function __construct(
        private GetCampaignMessagesHandler $handler
    ) {
    }
    #[OA\Get(
        path: '/api/v1/campaign/{campaign_id}/messages',
        summary: 'Get messages for a campaign',
        description: 'Returns messages belonging to a campaign, sorted by date descending. Useful for inspecting detected campaign content.',
        tags: ['Campaign Radar'],
        parameters: [
            new OA\Parameter(
                name: 'campaign_id',
                in: 'path',
                required: true,
                description: 'UUID de la campagne',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                description: 'Maximum number of messages to return (default: 10, max: 100)',
                schema: new OA\Schema(type: 'integer', default: 10, minimum: 1, maximum: 100)
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Messages retrieved successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid', description: 'UUID de la campagne'),
                        new OA\Property(property: 'messages_count', type: 'integer', description: 'Number of messages returned'),
                        new OA\Property(
                            property: 'messages',
                            type: 'array',
                            description: 'Liste des messages',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'msg_id', type: 'string', format: 'uuid', description: 'UUID du message'),
                                    new OA\Property(property: 'subject', type: 'string', nullable: true, description: 'Sujet du message'),
                                    new OA\Property(property: 'from', type: 'string', nullable: true, description: 'Sender (From header)'),
                                    new OA\Property(property: 'received_at', type: 'string', format: 'date-time', description: 'Received date'),
                                    new OA\Property(property: 'body_preview', type: 'string', description: 'Body preview (first 200 characters)'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid parameters',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campagne introuvable',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Error retrieving messages',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(string $campaign_id, Request $request): JsonResponse
    {
        // 1. Validation campaign_id
        try {
            $campaignId = Uuid::fromString($campaign_id);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Query string parameters
        $limit = (int) ($request->query->get('limit') ?? 10);

        if ($limit < 1 || $limit > 100) {
            return new JsonResponse(
                ['error' => 'limit must be between 1 and 100'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // 3. Appel handler
        try {
            $result = $this->handler->handle($campaignId, $limit);

            return new JsonResponse($result, Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return new JsonResponse(
                ['error' => 'Failed to fetch messages: ' . $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
