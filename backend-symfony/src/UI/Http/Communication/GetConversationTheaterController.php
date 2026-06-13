<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\TheaterAssemblyService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Spec 097 — Live Bait Theater composite endpoint.
 *
 * Returns conversation meta + ordered messages + deduplicated IOCs in a
 * SINGLE round-trip so the Theater UI can play back the extraction with
 * consistent state. Slice 2 will enrich each IOC with its
 * `revelation_context` and add the `human_factor` aggregate block.
 *
 * Read-only. Never mutates. Reuses `IocHandler::getConversationIocs` so
 * the Theater shows the EXACT same IOCs as the existing `/iocs` endpoint.
 */
#[OA\Get(
    path: '/api/v1/communication/conversation/{convId}/theater',
    summary: 'Spec 097 — Live Bait Theater composite payload (meta + messages + iocs)',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Theater payload'),
        new OA\Response(
            response: 404,
            description: 'Conversation not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetConversationTheaterController
{
    public function __construct(
        private ConversationHandler $handler,
        private TheaterAssemblyService $theater,
    ) {
    }

    #[Route('/api/v1/communication/conversation/{convId}/theater', name: 'get_conversation_theater', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId): JsonResponse
    {
        $conv = $this->handler->getConversation($convId);

        if (!$conv || $conv->getDeletedAt() instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->theater->assemble($conv), Response::HTTP_OK);
    }
}
