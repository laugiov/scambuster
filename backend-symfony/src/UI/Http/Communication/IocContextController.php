<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocContextQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/iocs/{indicatorId}/context',
    summary: 'Get contextual enrichment for an IOC indicator',
    description: 'Returns structural and semantic context for all observations of this indicator across conversations.',
    tags: ['IOCs'],
    parameters: [
        new OA\Parameter(name: 'indicatorId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'IOC context data'),
        new OA\Response(response: 404, description: 'Indicator not found'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/iocs/{indicatorId}/context', name: 'api_ioc_context', methods: ['GET'])]
#[IsGranted('ioc:read')]
final readonly class IocContextController
{
    public function __construct(
        private IocContextQueryService $iocContextQueryService,
    ) {
    }
    public function __invoke(string $indicatorId): JsonResponse
    {
        if (!$this->iocContextQueryService->indicatorExists($indicatorId)) {
            return new JsonResponse(
                ['error' => 'Indicator not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $contexts = $this->iocContextQueryService->findContextsByIndicatorId($indicatorId);

        return new JsonResponse([
            'indicator_id' => $indicatorId,
            'contexts' => $contexts,
        ]);
    }
}
