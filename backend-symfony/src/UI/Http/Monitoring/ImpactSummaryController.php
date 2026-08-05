<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\ImpactHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/impact/summary',
    summary: 'Impact summary: wasted time, IOC value, cost efficiency, campaigns',
    tags: ['Impact'],
    parameters: [
        new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'all', enum: ['7d', '30d', '90d', 'all'])),
        new OA\Parameter(name: 'scam_type', in: 'query', required: false, description: 'Optional filter by scam type code', schema: new OA\Schema(type: 'string')),
    ],
    responses: [new OA\Response(response: 200, description: 'Impact summary data')],
    security: [['Bearer' => []]],
)]
final readonly class ImpactSummaryController
{
    public function __construct(
        private ImpactHandler $handler,
    ) {
    }
    #[Route('/api/v1/impact/summary', name: 'api_impact_summary', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->query->getString('period', 'all');
        // Optional scam_type filter (e.g. INVOICE_FRAUD).
        $scamTypeRaw = $request->query->get('scam_type');
        $scamType = \is_string($scamTypeRaw) && trim($scamTypeRaw) !== '' ? trim($scamTypeRaw) : null;

        return new JsonResponse($this->handler->getSummary($period, $scamType), Response::HTTP_OK);
    }
}
