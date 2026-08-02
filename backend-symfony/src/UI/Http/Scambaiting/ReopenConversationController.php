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
 * Reopen a closed or abandoned conversation (manual analyst action).
 *
 * Reactivates the conversation and clears its recorded reward. The persona
 * statistics already credited to the bandit when it was closed are not rolled
 * back — the UI warns the analyst before proceeding.
 */
#[OA\Post(
    path: '/api/v1/scambaiting/conversation/{convId}/reopen',
    summary: 'Reopen a closed or abandoned conversation',
    tags: ['Scambaiting'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Conversation reopened successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Conversation reopened successfully'),
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        new OA\Response(
            response: 400,
            description: 'Business rule error (e.g. conversation not found or cannot be reopened)',
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
#[Route('/api/v1/scambaiting/conversation/{convId}/reopen', name: 'api_scambaiting_reopen_conversation', methods: ['POST'])]
#[IsGranted('conversation:write')]
final class ReopenConversationController extends AbstractController
{
    public function __construct(
        private readonly ConversationClosureService $closureService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(string $convId): JsonResponse
    {
        try {
            $user = $this->getUser();
            $actorId = $user?->getUserIdentifier() ?? 'user';
            $this->closureService->reopenConversation($convId, $actorId, 'user');

            $this->logger->info('Conversation reopened via API', [
                'conv_id' => $convId,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Conversation reopened successfully',
                'conv_id' => $convId,
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $this->logger->error('Failed to reopen conversation via API', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error reopening conversation', [
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
