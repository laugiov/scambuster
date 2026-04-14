<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\UI\Http\Dto\ConversationContextResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
final readonly class GetConversationContextController
{
    public function __construct(private ReplyHandler $handler)
    {
    }
    #[Route('/api/v1/communication/conversation/{convId}/context', name: 'get_conversation_context', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId): JsonResponse
    {
        $context = $this->handler->getConversationContext($convId);

        if (!$context) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        /** @var string $ctxConvId */
        $ctxConvId = $context['conv_id'] ?? '';
        /** @var string $ctxStatus */
        $ctxStatus = $context['status'] ?? '';
        /** @var array<string, mixed> $scamType */
        $scamType = $context['scam_type'] ?? [];
        /** @var string $ctxPersona */
        $ctxPersona = $context['persona'] ?? '';
        /** @var array<string, mixed> $cadence */
        $cadence = $context['cadence'] ?? [];
        /** @var array<int, mixed> $lastMessages */
        $lastMessages = $context['last_messages'] ?? [];
        /** @var string|null $senderHistory */
        $senderHistory = $context['sender_history_summary'] ?? null;
        $dto = new ConversationContextResponseDto(
            $ctxConvId,
            $ctxStatus,
            $scamType,
            $ctxPersona,
            $cadence,
            $lastMessages,
            $senderHistory
        );

        return new JsonResponse($dto->toArray(), Response::HTTP_OK);
    }
}
