<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use App\UI\Http\Dto\MessageDeleteResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Delete(
    path: '/api/v1/communication/message/{msgId}',
    summary: 'Supprimer un message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Message supprimé',
            content: new OA\JsonContent(ref: new Model(type: MessageDeleteResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class DeleteMessageController
{
    public function __construct(
        private MessageHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/message/{msgId}', name: 'delete_message', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $msgId): JsonResponse
    {
        $ok = $this->handler->deleteMessage($msgId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new MessageDeleteResponseDto('Message deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
