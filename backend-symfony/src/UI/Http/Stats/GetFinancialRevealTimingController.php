<?php

declare(strict_types=1);

namespace App\UI\Http\Stats;

use App\Application\Stats\FinancialRevealTimingService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Spec 100 S2 — GET /api/v1/stats/financial-reveal-timing.
 *
 * Returns the corpus-wide median and P75 of the turn at which the
 * first financial IOC is revealed across CLOSED conversations.
 * Lets the Theater render "Typical: turn X/Y · This conv: 12/13"
 * alongside the per-conv statistic.
 */
#[OA\Get(
    path: '/api/v1/stats/financial-reveal-timing',
    summary: 'Corpus median + P75 of the turn at which scammers first reveal a financial IOC',
    tags: ['Stats'],
    responses: [new OA\Response(response: 200, description: 'Timing aggregates')],
    security: [['Bearer' => []]],
)]
final readonly class GetFinancialRevealTimingController
{
    public function __construct(
        private FinancialRevealTimingService $service,
    ) {
    }

    #[Route('/api/v1/stats/financial-reveal-timing', name: 'stats_financial_reveal_timing', methods: ['GET'])]
    #[IsGranted('conversation:read')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->service->compute());
    }
}
