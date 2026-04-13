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
    path: '/api/v1/impact/ioc-uniqueness',
    summary: 'IOC uniqueness analysis with novel vs known breakdown',
    tags: ['Impact'],
    parameters: [
        new OA\Parameter(name: 'period', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '30d', enum: ['7d', '30d', '90d', 'all'])),
        new OA\Parameter(name: 'ioc_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
    ],
    responses: [new OA\Response(response: 200, description: 'IOC uniqueness data')],
    security: [['Bearer' => []]],
)]
final class IocUniquenessController
{
    public function __construct(
        private readonly ImpactHandler $handler,
    ) {
    }

    #[Route('/api/v1/impact/ioc-uniqueness', name: 'api_impact_ioc_uniqueness', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(Request $request): JsonResponse
    {
        $period = $request->query->getString('period', '30d');
        $iocType = $request->query->get('ioc_type');
        $iocType = \is_string($iocType) && '' !== $iocType ? $iocType : null;

        return new JsonResponse($this->handler->getIocUniqueness($period, $iocType), Response::HTTP_OK);
    }
}
