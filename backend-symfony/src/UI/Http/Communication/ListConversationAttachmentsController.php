<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\AttachmentHandler;
use App\UI\Http\Dto\AttachmentListItemDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/attachment/conversation/{convId}/attachments',
    summary: 'Lister les pièces jointes d\'une conversation',
    tags: ['Attachments'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste des pièces jointes',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: new Model(type: AttachmentListItemDto::class))
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation non trouvée',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ListConversationAttachmentsController
{
    public function __construct(private AttachmentHandler $handler)
    {
    }
    #[Route('/api/v1/communication/attachment/conversation/{convId}/attachments', name: 'list_conversation_attachments', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId): JsonResponse
    {
        $conversation = $this->handler->getConversation($convId);

        if (!$conversation instanceof \App\Domain\Communication\Conversation) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $attachments = $this->handler->listConversationAttachments($convId);

        if ($attachments === []) {
            return new JsonResponse([], Response::HTTP_OK);
        }
        $result = array_map(fn ($att): array => (new AttachmentListItemDto(
            $att->getAttachmentId(),
            $att->getFilename(),
            $att->getMimeType(),
            $att->getSizeBytes(),
            $att->getDeletedAt() ? $att->getDeletedAt()->format(DATE_ATOM) : null
        ))->toArray(), $attachments);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
