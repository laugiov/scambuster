<?php

declare(strict_types=1);

namespace App\UI\Http\Ttp;

use App\Application\Ttp\TtpQueryService;
use App\Domain\Communication\Ttp;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/ttps',
    summary: 'TTP taxonomy with usage counters',
    description: 'The full closed taxonomy, including entries without observations. Confirmed and review observation counts are reported separately; no evidence text is ever included.',
    tags: ['TTPs'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Taxonomy entries with counters',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'taxonomy_version', type: 'string', example: '1.0'),
                    new OA\Property(
                        property: 'ttps',
                        type: 'array',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'ttp_code', type: 'string', example: 'SB-T001'),
                                new OA\Property(property: 'ttp_label', type: 'string'),
                                new OA\Property(property: 'phase', type: 'string'),
                                new OA\Property(property: 'definition', type: 'string'),
                                new OA\Property(property: 'examples', type: 'array', items: new OA\Items(type: 'string')),
                                new OA\Property(
                                    property: 'external_refs',
                                    type: 'array',
                                    items: new OA\Items(
                                        type: 'object',
                                        properties: [
                                            new OA\Property(property: 'source_name', type: 'string', example: 'mitre-attack'),
                                            new OA\Property(property: 'external_id', type: 'string', example: 'T1566'),
                                        ]
                                    )
                                ),
                                new OA\Property(property: 'observation_count', type: 'integer'),
                                new OA\Property(property: 'conversation_count', type: 'integer'),
                                new OA\Property(property: 'first_seen', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'last_seen', type: 'string', format: 'date-time', nullable: true),
                                new OA\Property(property: 'review_count', type: 'integer'),
                            ]
                        )
                    ),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]],
)]
final readonly class ListTtpsController
{
    public function __construct(
        private TtpQueryService $queryService,
    ) {
    }

    #[Route('/api/v1/ttps', name: 'list_ttps', methods: ['GET'])]
    #[IsGranted('ioc:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'taxonomy_version' => Ttp::TAXONOMY_VERSION,
            'ttps' => $this->queryService->taxonomyOverview(),
        ], Response::HTTP_OK);
    }
}
