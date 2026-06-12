<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\ScammerEngagementCalculator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Spec 096 / C1 — Bias-corrected scammer engagement metric.
 *
 * Returns the rate at which scammers actually reply to our outbound
 * messages, computed per real sender (not per conversation) to correct
 * for the three biases documented in the Calculator service.
 */
#[OA\Get(
    path: '/api/v1/monitoring/analytics/scammer-engagement',
    summary: 'Bias-corrected scammer engagement rate (per real sender)',
    tags: ['Analytics'],
    parameters: [
        new OA\Parameter(
            name: 'censoring_hours',
            in: 'query',
            required: false,
            description: 'Hours before now() considered "still in-flight" (excluded from denominator). Default 96.',
            schema: new OA\Schema(type: 'integer', default: 96, minimum: 0, maximum: 8760),
        ),
        new OA\Parameter(
            name: 'scam_type',
            in: 'query',
            required: false,
            description: 'Restrict the metric to a single scam_type code (e.g. INVOICE_FRAUD).',
            schema: new OA\Schema(type: 'string'),
        ),
        new OA\Parameter(
            name: 'period',
            in: 'query',
            required: false,
            description: 'Spec 096 / C2b — restrict to conversations with ts_last within this window. Combines with scam_type.',
            schema: new OA\Schema(type: 'string', default: 'all', enum: ['7d', '30d', '90d', 'all']),
        ),
    ],
    responses: [new OA\Response(response: 200, description: 'Scammer engagement rate')],
    security: [['Bearer' => []]],
)]
final readonly class ScammerEngagementController
{
    public function __construct(
        private ScammerEngagementCalculator $calculator,
    ) {
    }

    #[Route('/api/v1/monitoring/analytics/scammer-engagement', name: 'api_analytics_scammer_engagement', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $censoringHours = $request->query->getInt('censoring_hours', ScammerEngagementCalculator::CENSORING_HOURS_DEFAULT);
        $scamType = $request->query->get('scam_type');

        if (\is_string($scamType) && trim($scamType) === '') {
            $scamType = null;
        }

        // Spec 096 / C2b — period combines with scam_type orthogonally.
        $period = $request->query->getString('period', 'all');

        $result = $this->calculator->calculate(
            censoringHours: $censoringHours,
            scamTypeFilter: \is_string($scamType) ? $scamType : null,
            period: $period,
        );

        return new JsonResponse($result, Response::HTTP_OK);
    }
}
