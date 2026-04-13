<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\MessageHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/message/{msgId}/iocs',
    summary: 'Lister les IOCs d\'un message',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Liste des IOCs',
            content: new OA\JsonContent(
                type: 'array',
                items: new OA\Items(type: 'object', properties: [
                    new OA\Property(property: 'obs_id', type: 'string'),
                    new OA\Property(property: 'ioc_id', type: 'string'),
                    new OA\Property(property: 'context', type: 'string'),
                    new OA\Property(property: 'ts_observed', type: 'string', format: 'date-time')
                ])
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class GetMessageIocsController
{
    public function __construct(
        private readonly MessageHandler $handler
    ) {
    }

    #[Route('/api/v1/communication/message/{msgId}/iocs', name: 'get_message_iocs', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $msgId): JsonResponse
    {
        $iocs = $this->handler->getMessageIocs($msgId);
        $result = array_map(fn ($ioc) => [
            'obs_id' => $ioc->getObsId(),
            'ioc_id' => $ioc->getIndicatorId(),
            'context' => $ioc->getContext(),
            'ts_observed' => $ioc->getTsObserved()->format(DATE_ATOM),
        ], $iocs);

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
