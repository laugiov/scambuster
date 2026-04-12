<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\AttachmentHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/attachment/{attachmentId}/download',
    summary: 'Télécharger une pièce jointe',
    description: 'Returns HTTP 501 until a real S3-compatible storage backend is wired (Spec 065a removed the FAKE_CONTENT placeholder).',
    tags: ['Attachments'],
    parameters: [
        new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 404,
            description: 'Pièce jointe non trouvée ou supprimée',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 501,
            description: 'Storage backend not configured — see Spec 065a in the security & quality roadmap',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'error', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                ]
            )
        ),
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class DownloadAttachmentController
{
    public function __construct(private AttachmentHandler $handler)
    {
    }

    #[Route('/api/v1/communication/attachment/{attachmentId}/download', name: 'download_attachment', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $attachmentId): JsonResponse
    {
        $attachment = $this->handler->getAttachment($attachmentId);

        if (!$attachment || $attachment->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Attachment not found'], Response::HTTP_NOT_FOUND);
        }

        // Spec 065a/H1: removed the FAKE_CONTENT placeholder + tempnam orphan.
        // Until a real S3-compatible storage adapter is wired (future spec),
        // every successfully resolved attachment returns HTTP 501 Not
        // Implemented with a documented JSON error body. Operators consuming
        // this endpoint MUST handle 501 explicitly.
        return new JsonResponse(
            [
                'error' => 'Attachment storage backend not configured',
                'code' => 'STORAGE_NOT_CONFIGURED',
            ],
            Response::HTTP_NOT_IMPLEMENTED
        );
    }
}
