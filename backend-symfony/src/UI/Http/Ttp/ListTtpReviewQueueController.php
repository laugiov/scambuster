<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/review-queue',
    summary: 'TTP observations awaiting analyst review',
    description: 'Read-only triage queue of review-status observations, newest message first. Carries taxonomy identity, confidence, conversation/message anchors, evidence offsets and extraction provenance — never the evidence text. The item list is capped; total always reports the full queue size.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Review queue (empty items when the queue is clear)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'items',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'obs_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                                new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                                new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'ts_msg', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'evidence_start', type: 'integer', nullable: true),
                                new OA\Property(property: 'evidence_end', type: 'integer', nullable: true),
                                new OA\Property(property: 'extraction_model', type: 'string'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'total', type: 'integer'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class ListTtpReviewQueueController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/review-queue', name: 'ttps_review_queue', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->reviewQueue());
    }
}
