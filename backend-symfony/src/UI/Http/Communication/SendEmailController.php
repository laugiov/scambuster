<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
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
final readonly class SendEmailController
{
    public function __construct(
        private ReplyHandler $handler,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/communication/reply/{msgId}/send-email', name: 'send_reply_email', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function __invoke(string $msgId): JsonResponse
    {
        try {
            $result = $this->handler->sendEmail($msgId);

            return new JsonResponse($result, Response::HTTP_OK);
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'not found')) {
                return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
            }

            if (str_contains($message, 'Safety checks') || str_contains($message, 'non-outbound')) {
                return new JsonResponse(['error' => 'Message is not sendable'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            // Log the full exception (root cause + stack) so SMTP transport errors
            // (auth failure, connection refused, TLS, etc.) are diagnosable.
            $this->logger->error('[SendEmailController] SMTP send failed', [
                'msg_id' => $msgId,
                'exception_class' => $e::class,
                'exception_message' => $message,
                'previous_class' => $e->getPrevious()?->__toString() === null ? null : $e->getPrevious()::class,
                'previous_message' => $e->getPrevious()?->getMessage(),
                'trace_short' => array_slice(explode("\n", $e->getTraceAsString()), 0, 5),
            ]);

            return new JsonResponse([
                'error' => 'SMTP send failed',
                'detail' => $message,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
