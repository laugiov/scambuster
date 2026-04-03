<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\UI\Http\Dto\ConversationContextResponseDto;
use App\UI\Http\Dto\ReplyComposeResponseDto;
use App\UI\Http\Dto\ReplyDetailResponseDto;
use App\UI\Http\Dto\ReplyGenerateResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/communication')]
final class ReplyController
{
    public function __construct(private ReplyHandler $handler)
    {
    }

    #[OA\Get(
        path: '/api/v1/communication/conversation/{convId}/context',
        summary: 'Obtenir le contexte d\'une conversation pour génération de réponse',
        tags: ['Reply'],
        parameters: [
            new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Contexte de la conversation',
                content: new OA\JsonContent(ref: new Model(type: ConversationContextResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Conversation non trouvée',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/conversation/{convId}/context', name: 'get_conversation_context', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function getContext(string $convId): JsonResponse
    {
        $context = $this->handler->getConversationContext($convId);

        if (!$context) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        $dto = new ConversationContextResponseDto(
            $context['conv_id'],
            $context['status'],
            $context['scam_type'],
            $context['persona'],
            $context['cadence'],
            $context['last_messages'],
            $context['sender_history_summary'] ?? null
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

    #[OA\Post(
        path: '/api/v1/communication/reply/generate',
        summary: 'Générer un brouillon de réponse',
        tags: ['Reply'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['conv_id', 'last_msg_id'],
                properties: [
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'last_msg_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'force', type: 'boolean', default: false),
                    new OA\Property(property: 'reason', type: 'string', example: 'auto_draft_on_inbound'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Brouillon généré avec succès',
                content: new OA\JsonContent(ref: new Model(type: ReplyGenerateResponseDto::class))
            ),
            new OA\Response(
                response: 400,
                description: 'Erreur de validation',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/reply/generate', name: 'generate_reply', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function generate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['conv_id']) || empty($data['last_msg_id'])) {
            return new JsonResponse(['error' => 'Missing required fields: conv_id, last_msg_id'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->handler->generateReply(
                $data['conv_id'],
                $data['last_msg_id'],
                $data['force'] ?? false,
                $data['reason'] ?? 'manual'
            );

            if (!$result) {
                return new JsonResponse(['error' => 'Could not generate reply'], Response::HTTP_BAD_REQUEST);
            }

            $dto = new ReplyGenerateResponseDto(
                $result['msg_id'],
                $result['conv_id'],
                $result['to'],
                $result['subject'],
                $result['draft'],
                $result['meta']
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[OA\Post(
        path: '/api/v1/communication/reply/draft',
        summary: 'Persister un brouillon (idempotent)',
        tags: ['Reply'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['msg_id', 'draft'],
                properties: [
                    new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'draft',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'text', type: 'string'),
                            new OA\Property(property: 'html', type: 'string'),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Brouillon sauvegardé'),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/reply/draft', name: 'save_reply_draft', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function saveDraft(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['msg_id']) || empty($data['draft'])) {
            return new JsonResponse(['error' => 'Missing required fields: msg_id, draft'], Response::HTTP_BAD_REQUEST);
        }

        // For now, this is a no-op since draft is already saved in generate
        // In a real implementation, this could update an existing draft
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[OA\Get(
        path: '/api/v1/communication/reply/{msgId}',
        summary: 'Récupérer un brouillon de réponse',
        tags: ['Reply'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Détail du brouillon',
                content: new OA\JsonContent(ref: new Model(type: ReplyDetailResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/reply/{msgId}', name: 'get_reply', methods: ['GET'])]
    #[IsGranted('reply:generate')]
    public function getReply(string $msgId): JsonResponse
    {
        $message = $this->handler->getMessage($msgId);

        if (!$message || $message->getDeletedAt() !== null) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Get parent message to retrieve Gmail Message ID for n8n Reply operation
        $parentGmailMsgId = null;
        $parentMessage = $message->getReplyTo();

        if ($parentMessage) {
            $parentGmailMsgId = $parentMessage->getProviderMsgId();
        }

        $dto = new ReplyDetailResponseDto(
            $message->getMsgId(),
            $message->getSendStatus() ?? 'unknown',
            $message->getHeaders()['to'] ?? '',
            $message->getSubject() ?? '',
            [
                'text' => $message->getBodyText(),
                'html' => $message->getBodyHtml(),
            ],
            [
                'parent_gmail_msg_id' => $parentGmailMsgId,
            ]
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/communication/reply/{msgId}/compose',
        summary: 'Obtenir les headers pour l\'envoi threadé',
        tags: ['Reply'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Headers de composition',
                content: new OA\JsonContent(ref: new Model(type: ReplyComposeResponseDto::class))
            ),
            new OA\Response(
                response: 404,
                description: 'Message non trouvé',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/reply/{msgId}/compose', name: 'compose_reply', methods: ['GET'])]
    #[IsGranted('reply:generate')]
    public function compose(string $msgId): JsonResponse
    {
        try {
            $composeData = $this->handler->composeHeaders($msgId);

            if (!$composeData) {
                return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
            }

            $dto = new ReplyComposeResponseDto(
                $composeData['msg_id'],
                $composeData['to'],
                $composeData['from'],
                $composeData['subject'],
                $composeData['in_reply_to'],
                $composeData['references'],
                $composeData['thread_id'],
                $composeData['safe_to_send'],
                $composeData['rate_limited'],
                $composeData['checks']
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[OA\Post(
        path: '/api/v1/communication/reply/{msgId}/sent',
        summary: 'Marquer une réponse comme envoyée',
        tags: ['Reply'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['provider', 'provider_msg_id', 'ts_sent'],
                properties: [
                    new OA\Property(property: 'provider', type: 'string', example: 'gmail'),
                    new OA\Property(property: 'provider_msg_id', type: 'string'),
                    new OA\Property(property: 'ts_sent', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid', description: 'Conversation ID (optional, used for header reconstruction)'),
                    new OA\Property(
                        property: 'sent_headers',
                        type: 'object',
                        description: 'Headers de threading (thread_id, message-id)',
                        properties: [
                            new OA\Property(property: 'thread_id', type: 'string', description: 'Gmail thread ID'),
                            new OA\Property(
                                property: 'message-id',
                                type: 'string',
                                description: 'RFC822 Message-ID généré par Gmail (sans les chevrons < >)',
                                example: 'CA+_aV9E5oKDFfGLmkNtuiG7Av0Ks1qAMks7fqFYym_CFV4mZLQ@mail.gmail.com'
                            ),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 204, description: 'Marqué comme envoyé'),
            new OA\Response(
                response: 400,
                description: 'Erreur (déjà envoyé ou invalide)',
                content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
            )
        ],
        security: [ [ 'Bearer' => [] ] ]
    )]
    #[Route('/reply/{msgId}/sent', name: 'mark_reply_sent', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function markSent(string $msgId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['provider']) || empty($data['provider_msg_id']) || empty($data['ts_sent'])) {
            return new JsonResponse(['error' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $tsSent = new \DateTimeImmutable($data['ts_sent']);
            $sentHeaders = $data['sent_headers'] ?? null;
            $convId = $data['conv_id'] ?? null;

            $success = $this->handler->markAsSent(
                $msgId,
                $data['provider'],
                $data['provider_msg_id'],
                $tsSent,
                $sentHeaders,
                $convId
            );

            if (!$success) {
                return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    #[OA\Post(
        path: '/api/v1/communication/reply/{msgId}/send-email',
        summary: 'Send a reply email via SMTP (Symfony Mailer)',
        description: 'Stateless: reads draft from DB, sends via MAILER_DSN with threading headers, returns Message-ID. Does NOT modify message state — call /sent after to confirm.',
        tags: ['Replies'],
        parameters: [
            new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Email sent successfully',
                content: new OA\JsonContent(type: 'object', properties: [
                    new OA\Property(property: 'success', type: 'boolean'),
                    new OA\Property(property: 'message_id', type: 'string'),
                    new OA\Property(property: 'ts_sent', type: 'string', format: 'date-time'),
                ])
            ),
            new OA\Response(response: 404, description: 'Message not found'),
            new OA\Response(response: 422, description: 'Message is not sendable (wrong direction or safety check failed)'),
            new OA\Response(response: 500, description: 'SMTP transport failure'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/reply/{msgId}/send-email', name: 'send_reply_email', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function sendEmail(string $msgId): JsonResponse
    {
        try {
            $result = $this->handler->sendEmail($msgId);

            return new JsonResponse($result, Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'not found')) {
                return new JsonResponse(['error' => $message], Response::HTTP_NOT_FOUND);
            }

            if (str_contains($message, 'Safety checks') || str_contains($message, 'non-outbound')) {
                return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new JsonResponse(['error' => 'SMTP send failed: ' . $message], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
