<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\UI\Http\Dto\ReplyDetailResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
final readonly class GetReplyController
{
    public function __construct(private ReplyHandler $handler)
    {
    }
    #[Route('/api/v1/communication/reply/{msgId}', name: 'get_reply', methods: ['GET'])]
    #[IsGranted('reply:generate')]
    public function __invoke(string $msgId): JsonResponse
    {
        $message = $this->handler->getMessage($msgId);

        if (!$message || $message->getDeletedAt() instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Get parent message to retrieve Gmail Message ID for n8n Reply operation
        $parentGmailMsgId = null;
        $parentMessage = $message->getReplyTo();

        if ($parentMessage instanceof \App\Domain\Communication\Message) {
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
}
