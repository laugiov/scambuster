<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\UI\Http\Dto\ConversationDetailResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/conversation/{convId}',
    summary: 'Détail d\'une conversation',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Détail de la conversation',
            content: new OA\JsonContent(ref: new Model(type: ConversationDetailResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation non trouvée',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class GetConversationController
{
    public function __construct(
        private ConversationHandler $handler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}', name: 'get_conversation', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId): JsonResponse
    {
        $conv = $this->handler->getConversation($convId);

        if (!$conv || $conv->getDeletedAt() instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }
        $links = $this->handler->getConversationChannels($conv);
        $channels = array_map(fn ($link): array => [
            'channel_id' => $link->getChannel()->getChannelId(),
            'code' => $link->getChannel()->getCode(),
            'label' => $link->getChannel()->getLabelFr(),
        ], $links);
        $dto = new ConversationDetailResponseDto(
            $conv->getConvId(),
            $conv->getStatus()->value,
            $conv->getScoreRisk(),
            $conv->getTsFirst()->format(DATE_ATOM),
            $conv->getTsLast()->format(DATE_ATOM),
            $conv->getStixId(),
            $channels
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
