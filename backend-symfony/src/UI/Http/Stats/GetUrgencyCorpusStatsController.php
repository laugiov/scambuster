<?php

declare(strict_types=1);

namespace App\UI\Http\Stats;

use App\Application\Stats\UrgencyCorpusStatsService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * GET /api/v1/stats/urgency-corpus.
 *
 * Returns corpus-wide median + P75 of `ioc_context.urgency_score`
 * across enriched IOCs. Consumed by the Theater per-IOC urgency bar
 * to render a corpus-baseline tick alongside the per-IOC reading.
 */
#[OA\Get(
    path: '/api/v1/stats/urgency-corpus',
    summary: 'Corpus median + P75 of scammer urgency across enriched IOCs',
    tags: ['Stats'],
    responses: [new OA\Response(response: 200, description: 'Urgency corpus stats')],
    security: [['Bearer' => []]],
)]
final readonly class GetUrgencyCorpusStatsController
{
    public function __construct(
        private UrgencyCorpusStatsService $service,
    ) {
    }

    #[Route('/api/v1/stats/urgency-corpus', name: 'stats_urgency_corpus', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->service->compute());
    }
}
