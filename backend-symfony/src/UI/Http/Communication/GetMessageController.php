<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use App\UI\Http\Dto\MessageResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/message/{msgId}',
    summary: 'Détail d\'un message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Détail du message',
            content: new OA\JsonContent(ref: new Model(type: MessageResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class GetMessageController
{
    public function __construct(
        private readonly MessageHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/message/{msgId}', name: 'get_message', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $msgId): JsonResponse
    {
        $message = $this->handler->getMessage($msgId);

        if (!$message || $message->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new MessageResponseDto(
            $message->getMsgId(),
            $message->getBodyText(),
            $message->getDirection()->getCode(),
            $message->getTsMsg()->format(DATE_ATOM),
            $message->getHeaders(),
            null
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
