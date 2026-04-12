<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use App\UI\Http\Dto\MessageAttachmentResponseDto;
use App\UI\Http\Dto\MessageCreateResponseDto;
use App\UI\Http\Dto\MessageDeleteResponseDto;
use App\UI\Http\Dto\MessageResponseDto;
use App\UI\Http\Dto\MessageRiskResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/communication/message')]
final class MessageController
{
    public function __construct(
        private readonly MessageHandler $handler,
        private readonly IocHandler $iocHandler,
        private readonly LoggerInterface $logger,
        // Spec 065h — extracted from Message::canExtractIocs()
        private readonly IocExtractionPolicy $iocExtractionPolicy = new IocExtractionPolicy()
    ) {
    }

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
    #[Route('', name: 'create_message', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function create(Request $request): JsonResponse
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
    #[Route('/{msgId}', name: 'get_message', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getMessage(string $msgId): JsonResponse
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

    #[OA\Delete(
        path: '/api/v1/communication/message/{msgId}',
        summary: 'Supprimer un message',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message supprimé',
                content: new OA\JsonContent(ref: new Model(type: MessageDeleteResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{msgId}', name: 'delete_message', methods: ['DELETE'])]
    #[IsGranted('conversation:write')]
    public function deleteMessage(string $msgId): JsonResponse
    {
        $ok = $this->handler->deleteMessage($msgId);

        if (!$ok) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = new MessageDeleteResponseDto('Message deleted');

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

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
    #[Route('/{msgId}/attachments', name: 'get_message_attachments', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getMessageAttachments(string $msgId): JsonResponse
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

    #[OA\Post(
        path: '/api/v1/communication/message/{msgId}/attachments',
        summary: 'Uploader une pièce jointe à un message',
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
                description: 'Pièce jointe uploadée',
                content: new OA\JsonContent(ref: new Model(type: MessageAttachmentResponseDto::class))
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation ou type/poids invalide',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
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
    #[Route('/{msgId}/attachments', name: 'upload_message_attachment', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function uploadAttachment(string $msgId, Request $request): JsonResponse
    {
        $message = $this->handler->getMessage($msgId);

        if (!$message) {
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

    #[OA\Patch(
        path: '/api/v1/communication/message/{msgId}',
        summary: 'Modifier un message',
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
                description: 'Message modifié',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation ou pas de changement',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{msgId}', name: 'patch_message', methods: ['PATCH'])]
    #[IsGranted('conversation:write')]
    public function patchMessage(string $msgId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $message = $this->handler->patchMessage($msgId, $data);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        if ($message === false) {
            return new JsonResponse(['error' => 'No change'], 400);
        }

        if (!$message) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['message' => 'Message updated'], 200);
    }

    #[OA\Get(
        path: '/api/v1/communication/message/{msgId}/iocs',
        summary: 'Lister les IOCs d\'un message',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des IOCs',
                content: new OA\JsonContent(
                    type: 'array',
                    items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'obs_id', type: 'string'),
                        new OA\Property(property: 'ioc_id', type: 'string'),
                        new OA\Property(property: 'context', type: 'string'),
                        new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time')
                    ])
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
    #[Route('/{msgId}/iocs', name: 'get_message_iocs', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getMessageIocs(string $msgId): JsonResponse
    {
        $iocs = $this->handler->getMessageIocs($msgId);
        $result = array_map(fn ($ioc) => [
            'obs_id' => $ioc->getObsId(),
            'ioc_id' => $ioc->getIndicatorId(),
            'context' => $ioc->getContext(),
            'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
        ], $iocs);

        return new JsonResponse($result, Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/communication/message/{msgId}/risk',
        summary: 'Get message risk score and decision recommendation',
        tags: ['Messages'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Risk score calculated',
                content: new OA\JsonContent(ref: new Model(type: MessageRiskResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Message not found',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{msgId}/risk', name: 'get_message_risk', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getMessageRisk(string $msgId): JsonResponse
    {
        try {
            $risk = $this->iocHandler->calculateMessageRisk($msgId);

            $dto = new MessageRiskResponseDto(
                $risk['score_agg'],
                $risk['level'],
                $risk['reason'],
                $risk['should_reply']
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }

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
    #[Route('/by-message-id/{messageId}', name: 'get_message_by_message_id', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getMessageByMessageId(string $messageId): JsonResponse
    {
        $message = $this->handler->getMessageByMessageId($messageId);

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

    #[OA\Post(
        path: '/api/v1/communication/message/{msgId}/extract-iocs',
        summary: 'Extract IOCs from a message using regex, LLM, or hybrid approach',
        tags: ['Messages', 'IOCs'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'method',
                        type: 'string',
                        enum: ['regex', 'llm', 'hybrid'],
                        description: 'Extraction method: regex (fast, known patterns), llm (contextual, AI-powered), hybrid (combines both)',
                        default: 'hybrid'
                    ),
                    new OA\Property(
                        property: 'types',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        description: 'IOC types to extract (e.g., ["ipv4", "url", "email"]). If empty, extracts all types.',
                        example: ['ipv4', 'url', 'email']
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'IOCs extracted successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'method', type: 'string', enum: ['regex', 'llm', 'hybrid']),
                        new OA\Property(property: 'iocs_found', type: 'integer', description: 'Number of IOCs found'),
                        new OA\Property(
                            property: 'iocs',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'type', type: 'string', example: 'ipv4'),
                                    new OA\Property(property: 'value', type: 'string', example: '192.168.1.1'),
                                    new OA\Property(property: 'value_norm', type: 'string', example: '192.168.1.1'),
                                    new OA\Property(property: 'context', type: 'object'),
                                ]
                            )
                        ),
                        new OA\Property(property: 'extraction_time_ms', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Message not found',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid method or parameters',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            ),
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/{msgId}/extract-iocs', name: 'extract_message_iocs', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function extractIocs(string $msgId, Request $request): JsonResponse
    {
        $this->logger->info('[IOC-EXTRACT-DEBUG] Starting IOC extraction', [
            'msg_id' => $msgId,
            'request_content' => $request->getContent(),
        ]);

        // Get message
        $message = $this->handler->getMessage($msgId);

        if (!$message || $message->getDeletedAt() !== null) {
            $this->logger->warning('[IOC-EXTRACT-DEBUG] Message not found', [
                'msg_id' => $msgId,
                'message_exists' => $message !== null,
                'deleted_at' => $message ? $message->getDeletedAt() : 'n/a',
            ]);

            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Spec 061: refuse extraction on outgoing messages.
        // Outgoing messages are LLM replies and pollute the indicator table with our
        // own headers + fictional 555 phone numbers invented by the persona.
        if (!$this->iocExtractionPolicy->allows($message)) {
            $this->logger->warning('[IOC-EXTRACT] Refused outgoing message extraction (spec 061)', [
                'msg_id' => $msgId,
                'direction' => $message->getDirection()->getCode(),
            ]);

            return new JsonResponse([
                'error' => 'IOC extraction is not allowed on outgoing messages',
                'msg_id' => $msgId,
                'direction' => $message->getDirection()->getCode(),
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('[IOC-EXTRACT-DEBUG] Message found', [
            'msg_id' => $msgId,
            'body_length' => strlen($message->getBodyText()),
            'body_preview' => substr($message->getBodyText(), 0, 200),
        ]);

        // Parse request body
        $data = json_decode($request->getContent(), true);

        if ($data === null && $request->getContent() !== '' && $request->getContent() !== '{}') {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $data */
        $method = $data['method'] ?? 'hybrid';
        $types = $data['types'] ?? [];
        $persist = $data['persist'] ?? false; // New parameter to persist IOCs

        $this->logger->info('[IOC-EXTRACT-DEBUG] Extraction parameters', [
            'msg_id' => $msgId,
            'method' => $method,
            'types' => $types,
            'persist' => $persist,
        ]);

        // Validate method
        $validMethods = ['regex', 'llm', 'hybrid'];

        if (!in_array($method, $validMethods, true)) {
            return new JsonResponse(
                ['error' => 'Invalid method. Must be one of: ' . implode(', ', $validMethods)],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Extract IOCs using IocHandler
        $startTime = microtime(true);

        try {
            $this->logger->info('[IOC-EXTRACT-DEBUG] Calling IocHandler->extractIocsFromMessage', [
                'msg_id' => $msgId,
                'method' => $method,
            ]);

            $iocs = $this->iocHandler->extractIocsFromMessage($msgId, $method, $types, $persist);
            $extractionTime = (int) ((microtime(true) - $startTime) * 1000);

            $this->logger->info('[IOC-EXTRACT-DEBUG] IOCs extracted successfully', [
                'msg_id' => $msgId,
                'iocs_found' => count($iocs),
                'iocs_preview' => array_slice($iocs, 0, 3),
                'extraction_time_ms' => $extractionTime,
            ]);

            $response = [
                'msg_id' => $msgId,
                'method' => $method,
                'iocs_found' => count($iocs),
                'iocs' => $iocs,
                'extraction_time_ms' => $extractionTime,
                'persisted' => $persist,
            ];

            // If persisted, add count of successfully added IOCs
            if ($persist) {
                $response['added'] = count($iocs);
            }

            return new JsonResponse($response, Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $this->logger->error('[IOC-EXTRACT-DEBUG] Extraction failed with exception', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
