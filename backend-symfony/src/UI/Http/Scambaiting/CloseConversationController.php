<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\ConversationClosureService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Closes a conversation and triggers reward calculation + stats update.
 * This endpoint is called by the n8n workflow WF-SCAMBAITING-END-CONVERSATION.
 */
#[OA\Post(
    path: '/api/v1/scambaiting/conversation/{convId}/close',
    summary: 'Close a conversation and trigger reward calculation',
    tags: ['Scambaiting'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Conversation closed successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Conversation closed successfully'),
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        new OA\Response(
            response: 400,
            description: 'Business rule error (e.g. conversation already closed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string'),
                ]
            )
        ),
        new OA\Response(
            response: 500,
            description: 'Unexpected server error',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string', example: 'Internal server error'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/conversation/{convId}/close', name: 'api_scambaiting_close_conversation', methods: ['POST'])]
#[IsGranted('conversation:close')]
final class CloseConversationController extends AbstractController
{
    public function __construct(
        private readonly ConversationClosureService $closureService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(string $convId): JsonResponse
    {
        try {
            // Spec 095 Fix #15 — pass the authenticated user identifier so
            // CONVERSATION_CLOSED audit rows can distinguish manual UI/API
            // closures from automated (cron) closures.
            $user = $this->getUser();
            $actorId = $user?->getUserIdentifier() ?? 'user';
            $this->closureService->closeConversation($convId, 'manual', $actorId, 'user');

            $this->logger->info('Conversation closed via API', [
                'conv_id' => $convId,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Conversation closed successfully',
                'conv_id' => $convId,
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to close conversation via API', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error closing conversation', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Internal server error',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
