<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\UI\Http\Dto\MessageRiskResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/message/{msgId}/risk',
    summary: 'Get message risk score and decision recommendation',
    tags: ['Messages'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Risk score calculated',
            content: new OA\JsonContent(ref: new Model(type: MessageRiskResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Message not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class GetMessageRiskController
{
    public function __construct(
        private IocHandler $iocHandler
    ) {
    }
    #[Route('/api/v1/communication/message/{msgId}/risk', name: 'get_message_risk', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $msgId): JsonResponse
    {
        try {
            $risk = $this->iocHandler->calculateMessageRisk($msgId);

            $dto = new MessageRiskResponseDto(
                $risk['score_agg'],
                $risk['level'],
                $risk['reason'],
                $risk['should_reply']
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}
