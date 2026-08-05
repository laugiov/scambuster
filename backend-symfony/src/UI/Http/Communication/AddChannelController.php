<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class AddChannelController
{
    public function __construct(
        private ConversationHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/conversation/{convId}/add-channel', name: 'add_channel_to_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data['channel_id'])) {
            return new JsonResponse(['error' => 'Missing channel_id'], Response::HTTP_BAD_REQUEST);
        }
        /** @var string|int $channelId */
        $channelId = $data['channel_id'];
        $channel = $this->handler->getChannel((string) $channelId);

        if (!$channel instanceof \App\Domain\Communication\Channel) {
            return new JsonResponse(['error' => 'Invalid reference'], Response::HTTP_BAD_REQUEST);
        }
        $ok = $this->handler->addChannelToConversation($convId, $channel);

        if ($ok) {
            return new JsonResponse(['message' => 'Channel added to conversation'], Response::HTTP_OK);
        }

        return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
    }
}
