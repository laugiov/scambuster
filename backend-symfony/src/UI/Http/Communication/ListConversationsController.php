<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\UI\Http\Dto\ConversationListItemDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/conversation',
    summary: 'Liste paginée des conversations',
    tags: ['Conversations'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste paginée des conversations',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(ref: new Model(type: ConversationListItemDto::class))
            )
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class ListConversationsController
{
    public function __construct(
        private readonly ConversationHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/conversation', name: 'list_conversations', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', '1'));
        $limit = max(1, (int)$request->query->get('limit', '20'));
        $offset = ($page - 1) * $limit;
        /** @var string|null $status */
        $status = $request->query->get('status');
        /** @var string|null $from */
        $from = $request->query->get('from');
        /** @var string|null $to */
        $to = $request->query->get('to');
        $convs = $this->handler->getFilteredConversations($page, $limit, $status, $from, $to);
        $convIds = array_values(array_map(static fn ($c) => $c->getConvId(), $convs));
        $messageCounts = $this->handler->getMessageCountsForConversations($convIds);
        $iocCounts = $this->handler->getIocCountsForConversations($convIds);
        $result = array_map(function ($conv) use ($messageCounts, $iocCounts) {
            $persona = $conv->getPersona();
            $scamType = $conv->getScamType();
            $dto = new ConversationListItemDto(
                $conv->getConvId(),
                $conv->getStatus()->value,
                $conv->getScoreRisk(),
                $conv->getTsFirst()->format(DATE_ATOM),
                $conv->getTsLast()->format(DATE_ATOM),
                $conv->getStixId(),
                $persona?->getPersonaCode(),
                $scamType->getCode(),
                $conv->getTurnsCount(),
                $messageCounts[$conv->getConvId()] ?? 0,
                $conv->getRewardValue(),
                $iocCounts[$conv->getConvId()] ?? 0,
            );

            return $dto->toArray();
        }, $convs);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
