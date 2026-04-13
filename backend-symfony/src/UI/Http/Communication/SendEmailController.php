<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
final class SendEmailController
{
    public function __construct(private ReplyHandler $handler)
    {
    }

    #[Route('/api/v1/communication/reply/{msgId}/send-email', name: 'send_reply_email', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function __invoke(string $msgId): JsonResponse
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
