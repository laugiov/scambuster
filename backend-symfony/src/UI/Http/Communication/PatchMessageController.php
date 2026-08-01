<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Patch(
    path: '/api/v1/communication/message/{msgId}',
    summary: 'Update a message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(type: 'object')
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Message updated',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
        ),
        new OA\Response(
            response: 400,
            description: 'Validation error or no changes',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 404,
            description: 'Message not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class PatchMessageController
{
    public function __construct(
        private MessageHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/message/{msgId}', name: 'patch_message', methods: ['PATCH'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $msgId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $data */
        try {
            $message = $this->handler->patchMessage($msgId, $data);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ($message === false) {
            return new JsonResponse(['error' => 'No change'], 400);
        }

        if (!$message instanceof \App\Domain\Communication\Message) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['message' => 'Message updated'], 200);
    }
}
