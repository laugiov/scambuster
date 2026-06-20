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
        private IocHandler $iocHandler,
        // Spec 110 — see services.yaml `app.risk_extraction_wait_sec`.
        // Prod/dev: 30s, test/e2e: 0s. Defaulting to 0 makes the wait a
        // strict opt-in behaviour for anyone constructing this controller
        // without DI.
        private int $riskExtractionWaitSec = 0,
    ) {
    }

    #[Route('/api/v1/communication/message/{msgId}/risk', name: 'get_message_risk', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(string $msgId): JsonResponse
    {
        // Spec 110 — race-condition mitigation. n8n's WF-INTAKE-EMAIL-V2
        // runs `Get Risk Assessment` (this endpoint) and
        // `WF-EXTRACT-AND-ENRICH-IOC` in parallel after ingest. The
        // extraction branch needs ~5–10s to populate body IOCs (url,
        // domain) into observed_ioc. Without this sleep, /risk computes
        // the intrinsic score on header IOCs only, which can fall below
        // the reply threshold (medium: >= 40) and cause n8n to silently
        // skip reply on legitimate scammer responses. 30s is the
        // configured cap; if extraction is faster the worker simply
        // sleeps the remainder. See spec 110 for the full audit.
        if ($this->riskExtractionWaitSec > 0) {
            sleep($this->riskExtractionWaitSec);
        }

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
