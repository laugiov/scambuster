<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use App\UI\Http\Dto\MessageCreateResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/message',
    summary: 'Créer un message',
    tags: ['Messages'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['conv_id', 'channel_id', 'direction', 'body_text', 'headers', 'ts_msg'],
            properties: [
                new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'channel_id', type: 'integer'),
                new OA\Property(property: 'direction', type: 'string', example: 'in'),
                new OA\Property(property: 'body_text', type: 'string'),
                new OA\Property(property: 'headers', type: 'object', additionalProperties: true),
                new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Message créé',
            content: new OA\JsonContent(ref: new Model(type: MessageCreateResponseDto::class))
        ),
        new OA\Response(
            response: 400,
            description: 'Erreur de validation ou de référence',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 409,
            description: 'Conflit (ex: doublon)',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class CreateMessageController
{
    public function __construct(
        private readonly MessageHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/message', name: 'create_message', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        foreach (['conv_id', 'channel_id', 'direction', 'body_text', 'headers', 'ts_msg'] as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            $message = $this->handler->createMessage($data);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        if (!$message) {
            return new JsonResponse(['error' => 'Invalid reference'], Response::HTTP_BAD_REQUEST);
        }
        $dto = new MessageCreateResponseDto(
            $message->getMsgId(),
            $message->getConversation()->getConvId(),
            $message->getTsMsg()->format(DATE_ATOM)
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
    }
}
