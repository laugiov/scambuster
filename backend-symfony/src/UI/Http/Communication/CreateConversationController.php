<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\Domain\Communication\ConversationStatus;
use App\UI\Http\Dto\ConversationCreateResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/conversation',
    summary: 'Créer une conversation',
    tags: ['Conversations'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['primary_channel_id', 'scam_type_id', 'account_id', 'status', 'score_risk', 'ts_first', 'ts_last', 'stix_id'],
            properties: [
                new OA\Property(property: 'primary_channel_id', type: 'integer'),
                new OA\Property(property: 'scam_type_id', type: 'integer'),
                new OA\Property(property: 'account_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'status', type: 'string', example: 'open'),
                new OA\Property(property: 'score_risk', type: 'integer'),
                new OA\Property(property: 'ts_first', type: 'string', format: 'date-time'),
                new OA\Property(property: 'ts_last', type: 'string', format: 'date-time'),
                new OA\Property(property: 'stix_id', type: 'string'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Conversation créée',
            content: new OA\JsonContent(ref: new Model(type: ConversationCreateResponseDto::class))
        ),
        new OA\Response(
            response: 400,
            description: 'Erreur de validation ou de référence',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'error', type: 'string')
                ]
            )
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class CreateConversationController
{
    public function __construct(
        private readonly ConversationHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/conversation', name: 'create_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        foreach (['primary_channel_id', 'scam_type_id', 'account_id', 'status', 'score_risk', 'ts_first', 'ts_last', 'stix_id'] as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(['error' => "Missing field: $field"], Response::HTTP_BAD_REQUEST);
            }
        }
        /** @var array<string, string|int|null> $data */
        $channel = $this->handler->getChannel((string)$data['primary_channel_id']);
        $scamType = $this->handler->getScamType((string)$data['scam_type_id']);
        $account = $this->handler->getMailAccount((string)$data['account_id']);
        $status = ConversationStatus::tryFrom((string)$data['status']);

        if (!$channel || !$scamType || !$account || !$status) {
            return new JsonResponse(['error' => 'Invalid reference'], Response::HTTP_BAD_REQUEST);
        }
        $scoreRisk = (int)$data['score_risk'];
        $tsFirst = new \DateTimeImmutable((string)$data['ts_first']);
        $tsLast = new \DateTimeImmutable((string)$data['ts_last']);
        $stixId = (string)$data['stix_id'];
        $conv = $this->handler->createConversation(
            $channel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            $tsFirst,
            $tsLast,
            $stixId
        );
        $dto = new ConversationCreateResponseDto($conv->getConvId(), $conv->getStatus()->value);

        return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
    }
}
