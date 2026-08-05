<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/stimulus-matrix',
    summary: 'Stimulus x TTP matrix over the revelation-message population',
    description: 'Sparse stimulus x TTP grid restricted to confirmed TTP observations on messages that also carry an enriched ioc_context with a non-null stimulus_type (validated join o.msg_id = oi.msg_id, oi.obs_id = ic.obs_id). A confirmed observation on a message with no enriched stimulus context is absent from the grid; population_messages reports the distinct messages in scope so the population can be stated honestly. UNKNOWN is kept as a stimulus value (the consumer decides whether to collapse it). Each cell carries message_count (distinct messages) and conversation_count. No evidence text is ever included.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Stimulus x TTP matrix (empty stimuli/cells when nothing is observed)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'stimuli',
                        type: 'array',
                        items: new OA\Items(type: 'string', example: 'URGENCY_PRESSURE')
                    ),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                            ]
                        )
                    ),
                    new OA\Property(
                        property: 'cells',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'stimulus_type', type: 'string', example: 'URGENCY_PRESSURE'),
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T017'),
                                new OA\Property(property: 'message_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                            ]
                        )
                    ),
                    new OA\Property(property: 'population_messages', type: 'integer'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpStimulusMatrixController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/stimulus-matrix', name: 'ttps_stimulus_matrix', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->queryService->stimulusMatrix());
    }
}
