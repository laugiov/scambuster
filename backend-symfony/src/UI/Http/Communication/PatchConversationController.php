<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Patch(
    path: '/api/v1/communication/conversation/{convId}',
    summary: 'Modifier une conversation',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'open', description: 'Statut de la conversation'),
                new OA\Property(property: 'score_risk', type: 'integer', example: 75, description: 'Score de risque (0-100)'),
                new OA\Property(property: 'ts_last', type: 'string', format: 'date-time', description: 'Date du dernier message'),
                new OA\Property(property: 'stix_id', type: 'string', description: 'Identifiant STIX'),
                new OA\Property(property: 'scam_type_id', type: 'integer', example: 4, description: 'Scam type ID (allows changing the assigned persona)')
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Conversation updated',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 400,
            description: 'Erreur de validation',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class PatchConversationController
{
    public function __construct(
        private ConversationHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}', name: 'patch_conversation', methods: ['PATCH'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $data */
        try {
            $conv = $this->handler->patchConversation($convId, $data);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ($conv === false) {
            return new JsonResponse(['error' => 'No change'], 400);
        }

        if (!$conv instanceof \App\Domain\Communication\Conversation) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['message' => 'Conversation updated'], 200);
    }
}
