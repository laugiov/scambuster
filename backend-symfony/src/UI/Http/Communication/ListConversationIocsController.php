<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\IocHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/conversation/{convId}/iocs',
    summary: 'List IOCs for a conversation (deduplicated)',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Deduplicated list of IOCs',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'obs_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'ioc_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'type', type: 'string'),
                    new OA\Property(property: 'value', type: 'string'),
                    new OA\Property(property: 'value_norm', type: 'string'),
                    new OA\Property(property: 'score', type: 'object'),
                    new OA\Property(property: 'category', type: 'string'),
                    new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time')
                ])
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ListConversationIocsController
{
    public function __construct(
        private ConversationHandler $handler,
        private IocHandler $iocHandler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}/iocs', name: 'list_conversation_iocs', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $convId): JsonResponse
    {
        $conv = $this->handler->getConversation($convId);

        if (!$conv || $conv->getDeletedAt() instanceof \DateTimeImmutable) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        // Delegate to IocHandler for deduplicated IOC list
        $iocs = $this->iocHandler->getConversationIocs($convId);

        $result = array_map(function ($ioc): array {
            $confidenceData = $this->iocHandler->computeConfidenceData(
                $ioc->getIndicatorId(),
                $ioc->getConfidenceScore(),
                $ioc->getTsObserved(),
            );

            return [
                'obs_id' => $ioc->getObsId(),
                'ioc_id' => $ioc->getIndicatorId(),
                'type' => $ioc->getContext()['type'] ?? '',
                'value' => $ioc->getContext()['value'] ?? '',
                'value_norm' => $ioc->getContext()['value_norm'] ?? '',
                'score' => $ioc->getContext()['score'] ?? [],
                'category' => $ioc->getContext()['category'] ?? '',
                'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
                'confidence' => $confidenceData['confidence'],
                'decay_factor' => $confidenceData['decay_factor'],
                'effective_score' => $confidenceData['effective_score'],
            ];
        }, $iocs);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
