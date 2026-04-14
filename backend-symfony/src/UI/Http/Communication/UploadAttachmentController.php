<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use App\UI\Http\Dto\MessageAttachmentResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/message/{msgId}/attachments',
    summary: 'Upload an attachment to a message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: 'multipart/form-data',
            schema: new OA\Schema(
                type: 'object',
                properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary')
                ]
            )
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Attachment uploaded',
            content: new OA\JsonContent(ref: new Model(type: MessageAttachmentResponseDto::class))
        ),
        new OA\Response(
            response: 400,
            description: 'Validation error or invalid type/size',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 404,
            description: 'Message not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 413,
            description: 'Fichier trop volumineux',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class UploadAttachmentController
{
    public function __construct(
        private MessageHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/message/{msgId}/attachments', name: 'upload_message_attachment', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $msgId, Request $request): JsonResponse
    {
        $message = $this->handler->getMessage($msgId);

        if (!$message instanceof \App\Domain\Communication\Message) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $file */
        $file = $request->files->get('file');

        if (!$file) {
            return new JsonResponse(['error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }
        $maxSize = 5 * 1024 * 1024; // 5 Mo
        $allowedTypes = ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'];

        if ($file->getSize() > $maxSize) {
            return new JsonResponse(['error' => 'File too large'], 413);
        }

        if (!in_array($file->getMimeType(), $allowedTypes, true)) {
            return new JsonResponse(['error' => 'Invalid file type'], 400);
        }
        $attachment = $this->handler->addAttachmentToMessage($message, $file);
        $dto = new MessageAttachmentResponseDto($attachment->getAttachmentId());

        return new JsonResponse($dto->toArray(), 201);
    }
}
