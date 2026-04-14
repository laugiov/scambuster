<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/conversation/{convId}/messages',
    summary: 'Liste paginée des messages d\'une conversation',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste paginée des messages',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'message_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'direction', type: 'string', enum: ['in', 'out']),
                    new OA\Property(property: 'subject', type: 'string', nullable: true),
                    new OA\Property(property: 'body_text', type: 'string'),
                    new OA\Property(property: 'body_html', type: 'string', nullable: true),
                    new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'lang_detect', type: 'string'),
                    new OA\Property(property: 'external_message_id', type: 'string', nullable: true),
                ])
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
final readonly class ListConversationMessagesController
{
    public function __construct(
        private ConversationHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}/messages', name: 'list_conversation_messages', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId, Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = max(1, (int)$request->query->get('limit', 20));

        $result = $this->handler->getConversationMessages($convId, $page, $limit);

        if ($result['total'] === 0 && $page === 1) {
            // Check if conversation exists (only on first page with no results)
            $conv = $this->handler->getConversation($convId);

            if (!$conv || $conv->getDeletedAt() instanceof \DateTimeImmutable) {
                return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
            }
        }

        /** @var list<\App\Domain\Communication\Message> $resultMessages */
        $resultMessages = $result['messages'];
        $items = array_map(fn (\App\Domain\Communication\Message $msg): array => [
            'message_id' => $msg->getMsgId(),
            'direction' => $msg->getDirection()->getCode(),
            'subject' => $msg->getSubject(),
            'body_text' => $msg->getBodyText(),
            'body_html' => $msg->getBodyHtml(),
            'ts_msg' => $msg->getTsMsg()->format(\DateTimeInterface::ATOM),
            'lang_detect' => $msg->getLangDetect(),
            'external_message_id' => $msg->getExternalMessageId(),
        ], $resultMessages);

        return new JsonResponse($items, Response::HTTP_OK);
    }
}
