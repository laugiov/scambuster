<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for IOC detail page — returns full indicator data
 * with observations and related IOCs.
 */
#[Route('/api/v1/iocs')]
#[IsGranted('ioc:read')]
final readonly class IocDetailController
{
    public function __construct(
        private IocHandler $handler
    ) {
    }
    #[OA\Get(
        path: '/api/v1/iocs/{indicator_id}/detail',
        summary: 'Get full IOC detail with observations and related IOCs',
        tags: ['IOCs'],
        parameters: [
            new OA\Parameter(
                name: 'indicator_id',
                in: 'path',
                required: true,
                description: 'Indicator UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'IOC detail with observations and related IOCs',
            ),
            new OA\Response(
                response: 404,
                description: 'Indicator not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/{indicator_id}/detail', name: 'ioc_detail', methods: ['GET'])]
    public function __invoke(string $indicator_id): JsonResponse
    {
        try {
            $detail = $this->handler->getIocDetail($indicator_id);

            return new JsonResponse($detail, Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}
