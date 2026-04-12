<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
final class MarkReplySentController
{
    public function __construct(private ReplyHandler $handler)
    {
    }

    #[Route('/api/v1/communication/reply/{msgId}/sent', name: 'mark_reply_sent', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function __invoke(string $msgId, Request $request): JsonResponse
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
}
