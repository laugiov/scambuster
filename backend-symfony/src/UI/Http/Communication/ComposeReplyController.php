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
    summary: 'Get headers for threaded sending',
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
            description: 'Message not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ComposeReplyController
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

            /** @var string $cMsgId */
            $cMsgId = $composeData['msg_id'] ?? '';
            /** @var string $cTo */
            $cTo = $composeData['to'] ?? '';
            /** @var string $cFrom */
            $cFrom = $composeData['from'] ?? '';
            /** @var string $cSubject */
            $cSubject = $composeData['subject'] ?? '';
            /** @var string|null $cInReplyTo */
            $cInReplyTo = $composeData['in_reply_to'] ?? null;
            /** @var string|null $cReferences */
            $cReferences = $composeData['references'] ?? null;
            /** @var string|null $cThreadId */
            $cThreadId = $composeData['thread_id'] ?? null;
            /** @var bool $cSafe */
            $cSafe = $composeData['safe_to_send'] ?? false;
            /** @var bool $cRateLimited */
            $cRateLimited = $composeData['rate_limited'] ?? false;
            /** @var array<string, mixed> $checks */
            $checks = $composeData['checks'] ?? [];
            $dto = new ReplyComposeResponseDto(
                $cMsgId,
                $cTo,
                $cFrom,
                $cSubject,
                $cInReplyTo,
                $cReferences,
                $cThreadId,
                $cSafe,
                $cRateLimited,
                $checks
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
