<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\AttachmentHandler;
use App\UI\Http\Dto\AttachmentDeleteResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Delete(
    path: '/api/v1/communication/attachment/{attachmentId}',
    summary: 'Delete an attachment',
    tags: ['Attachments'],
    parameters: [
        new OA\Parameter(name: 'attachmentId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Attachment deleted',
            content: new OA\JsonContent(ref: new Model(type: AttachmentDeleteResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Attachment not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class DeleteAttachmentController
{
    public function __construct(private AttachmentHandler $handler)
    {
    }
    #[Route('/api/v1/communication/attachment/{attachmentId}', name: 'delete_attachment', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $attachmentId): JsonResponse
    {
        $ok = $this->handler->deleteAttachment($attachmentId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Attachment not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new AttachmentDeleteResponseDto('Attachment deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
