<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\UI\Http\Dto\ConversationDeleteResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Delete(
    path: '/api/v1/communication/conversation/{convId}',
    summary: 'Supprimer une conversation',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Conversation supprimée',
            content: new OA\JsonContent(ref: new Model(type: ConversationDeleteResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation non trouvée',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class DeleteConversationController
{
    public function __construct(
        private ConversationHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}', name: 'delete_conversation', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $convId): JsonResponse
    {
        $ok = $this->handler->deleteConversation($convId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new ConversationDeleteResponseDto('Conversation deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
