<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps/sequences',
    summary: 'Top TTP sequences per threat-actor cluster or scam type',
    description: 'Top adjacent-pair TTP sequences per group, confirmed observations only. Pairs are folded across message boundaries on the message-timestamp axis (same-message TTPs are an unordered co-occurrence set; self-pairs are excluded). min_support is the minimum distinct conversations a pair must appear in: pairs seen in fewer conversations are dropped server-side and groups without any reportable pair are omitted (count still reports raw occurrences). The group set is capped (widest conversation volume first) with an explicit truncated flag. No evidence text is ever included.',
    tags: ['TTPs'],
    parameters: [
        new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['cluster', 'scam_type'], default: 'cluster')),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Top sequences per group (empty groups list when nothing clears the support threshold)',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(
                        property: 'groups',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'key', type: 'string', example: 'ADVANCE_FEE'),
                                new OA\Property(property: 'label', type: 'string'),
                                new OA\Property(
                                    property: 'sequences',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'sequence', type: 'array', items: new OA\Items(type: 'string'), example: ['SB-T001', 'SB-T017']),
                                            new OA\Property(property: 'count', type: 'integer'),
                                            new OA\Property(property: 'conversation_count', type: 'integer'),
                                        ]
                                    )
                                ),
                            ]
                        )
                    ),
                    new OA\Property(property: 'min_support', type: 'integer', example: 2),
                    new OA\Property(property: 'truncated', type: 'boolean'),
                ]
            )
        ),
        new OA\Response(
            response: 400,
            description: 'Invalid group',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class GetTtpSequencesController
{
    private const VALID_GROUPS = ['cluster', 'scam_type'];

    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps/sequences', name: 'ttps_sequences', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $group = (string) $request->query->get('group', 'cluster');

        if (!\in_array($group, self::VALID_GROUPS, true)) {
            return new JsonResponse(['error' => 'Invalid group'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->queryService->sequences($group));
    }
}
