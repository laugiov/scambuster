<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use App\UI\Http\Dto\MessageAttachmentResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/message/{msgId}/attachments',
    summary: 'Lister les pièces jointes d\'un message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste des pièces jointes',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: new Model(type: MessageAttachmentResponseDto::class))
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class GetMessageAttachmentsController
{
    public function __construct(
        private readonly MessageHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/message/{msgId}/attachments', name: 'get_message_attachments', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $msgId): JsonResponse
    {
        $attachments = $this->handler->getMessageAttachments($msgId);
        $result = array_map(fn ($att) => [
            'attachment_id' => $att->getAttachmentId(),
            'filename' => $att->getFilename(),
            'mime_type' => $att->getMimeType(),
            'size_bytes' => $att->getSizeBytes(),
            'deleted_at' => $att->getDeletedAt() ? $att->getDeletedAt()->format(DATE_ATOM) : null,
        ], $attachments);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
