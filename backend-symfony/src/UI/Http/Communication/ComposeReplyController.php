<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\UI\Http\Dto\ReplyComposeResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/communication/reply/{msgId}/compose',
    summary: 'Obtenir les headers pour l\'envoi threadé',
    tags: ['Reply'],
    parameters: [
        new OA\Parameter(name: 'msgId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Headers de composition',
            content: new OA\JsonContent(ref: new Model(type: ReplyComposeResponseDto::class))
        ),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class ComposeReplyController
{
    public function __construct(private ReplyHandler $handler)
    {
    }

    #[Route('/api/v1/communication/reply/{msgId}/compose', name: 'compose_reply', methods: ['GET'])]
    #[IsGranted('reply:generate')]
    public function __invoke(string $msgId): JsonResponse
    {
        try {
            $composeData = $this->handler->composeHeaders($msgId);

            if (!$composeData) {
                return new JsonResponse(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
            }

            $dto = new ReplyComposeResponseDto(
                $composeData['msg_id'],
                $composeData['to'],
                $composeData['from'],
                $composeData['subject'],
                $composeData['in_reply_to'],
                $composeData['references'],
                $composeData['thread_id'],
                $composeData['safe_to_send'],
                $composeData['rate_limited'],
                $composeData['checks']
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
