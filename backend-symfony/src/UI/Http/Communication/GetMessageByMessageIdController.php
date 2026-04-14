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
    path: '/api/v1/communication/message/by-message-id/{messageId}',
    summary: 'Récupère un message par son message-id',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'messageId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Message trouvé',
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
final readonly class GetMessageByMessageIdController
{
    public function __construct(
        private MessageHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/message/by-message-id/{messageId}', name: 'get_message_by_message_id', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $messageId): JsonResponse
    {
        $message = $this->handler->getMessageByMessageId($messageId);

        if (!$message || $message->getDeletedAt() instanceof \DateTimeImmutable) {
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
