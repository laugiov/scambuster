<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\Domain\LLM\Exception\LlmBudgetExceededException;
use App\UI\Http\Dto\ReplyGenerateResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/reply/generate',
    summary: 'Generate a reply draft',
    tags: ['Reply'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['conv_id', 'last_msg_id'],
            properties: [
                new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'last_msg_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'force', type: 'boolean', default: false),
                new OA\Property(property: 'reason', type: 'string', example: 'auto_draft_on_inbound'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 201,
            description: 'Draft generated successfully',
            content: new OA\JsonContent(ref: new Model(type: ReplyGenerateResponseDto::class))
        ),
        new OA\Response(
            response: 400,
            description: 'Validation error',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class GenerateReplyController
{
    public function __construct(private ReplyHandler $handler)
    {
    }
    #[Route('/api/v1/communication/reply/generate', name: 'generate_reply', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['conv_id']) || empty($data['last_msg_id'])) {
            return new JsonResponse(['error' => 'Missing required fields: conv_id, last_msg_id'], Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var string $grConvId */
            $grConvId = $data['conv_id'];
            /** @var string $grLastMsgId */
            $grLastMsgId = $data['last_msg_id'];
            $result = $this->handler->generateReply(
                $grConvId,
                $grLastMsgId,
                !empty($data['force']),
                \is_string($data['reason'] ?? null) ? $data['reason'] : 'manual'
            );

            if (!$result) {
                return new JsonResponse(['error' => 'Could not generate reply'], Response::HTTP_BAD_REQUEST);
            }

            /** @var string $rMsgId */
            $rMsgId = $result['msg_id'] ?? '';
            /** @var string $rConvId */
            $rConvId = $result['conv_id'] ?? '';
            /** @var string $rTo */
            $rTo = $result['to'] ?? '';
            /** @var string $rSubject */
            $rSubject = $result['subject'] ?? '';
            /** @var array<string, mixed> $draft */
            $draft = $result['draft'] ?? [];
            /** @var array<string, mixed> $meta */
            $meta = $result['meta'] ?? [];
            $dto = new ReplyGenerateResponseDto(
                $rMsgId,
                $rConvId,
                $rTo,
                $rSubject,
                $draft,
                $meta
            );

            return new JsonResponse($dto->toArray(), Response::HTTP_CREATED);
        } catch (LlmBudgetExceededException $e) {
            // Spec 065b — LLM monthly budget exhausted. Return HTTP 503
            // with a Retry-After header pointing to the next month rollover
            // so HTTP clients can resume automatically.
            $retryAfterSeconds = max(0, $e->resetAt->getTimestamp() - time());
            $response = new JsonResponse(
                [
                    'error' => 'LLM monthly budget exceeded',
                    'code' => 'BUDGET_EXCEEDED',
                    'current_usd' => $e->currentUsdSpent,
                    'limit_usd' => $e->monthlyLimitUsd,
                    'reset_at' => $e->resetAt->format(\DateTimeInterface::ATOM),
                ],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
            $response->headers->set('Retry-After', (string) $retryAfterSeconds);

            return $response;
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
