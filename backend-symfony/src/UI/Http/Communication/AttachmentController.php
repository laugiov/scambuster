<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\AttachmentHandler;
use App\UI\Http\Dto\AttachmentDeleteResponseDto;
use App\UI\Http\Dto\AttachmentListItemDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/communication/attachment')]
final class AttachmentController
{
    public function __construct(private AttachmentHandler $handler)
    {
    }

    #[OA\Delete(
        path: '/api/v1/communication/attachment/{attachmentId}',
        summary: 'Supprimer une pièce jointe',
        tags: ['Attachments'],
        parameters: [
            new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pièce jointe supprimée',
                content: new OA\JsonContent(ref: new Model(type: AttachmentDeleteResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Pièce jointe non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{attachmentId}', name: 'delete_attachment', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function deleteAttachment(string $attachmentId): JsonResponse
    {
        $ok = $this->handler->deleteAttachment($attachmentId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Attachment not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new AttachmentDeleteResponseDto('Attachment deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/communication/attachment/{attachmentId}/download',
        summary: 'Télécharger une pièce jointe',
        tags: ['Attachments'],
        parameters: [
            new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Fichier téléchargé',
                content: new OA\MediaType(
                    mediaType: 'application/octet-stream',
                    schema: new OA\Schema(type: 'string', format: 'binary')
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Pièce jointe non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{attachmentId}/download', name: 'download_attachment', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function downloadAttachment(string $attachmentId): Response
    {
        $attachment = $this->handler->getAttachment($attachmentId);

        if (!$attachment || $attachment->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Attachment not found'], Response::HTTP_NOT_FOUND);
        }
        // Pour la démo, on retourne un fichier vide ou un contenu fictif
        $tmpFile = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmpFile, 'FAKE_CONTENT');
        $response = new BinaryFileResponse($tmpFile);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $attachment->getFilename()
        );
        $response->headers->set('Content-Type', $attachment->getMimeType());

        return $response;
    }

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
    #[Route('/conversation/{convId}/attachments', name: 'list_conversation_attachments', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function listConversationAttachments(string $convId): JsonResponse
    {
        $conversation = $this->handler->getConversation($convId);

        if (!$conversation) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $attachments = $this->handler->listConversationAttachments($convId);

        if (empty($attachments)) {
            return new JsonResponse([], Response::HTTP_OK);
        }
        $result = array_map(fn ($att) => (new AttachmentListItemDto(
            $att->getAttachmentId(),
            $att->getFilename(),
            $att->getMimeType(),
            $att->getSizeBytes(),
            $att->getDeletedAt() ? $att->getDeletedAt()->format(DATE_ATOM) : null
        ))->toArray(), $attachments);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
