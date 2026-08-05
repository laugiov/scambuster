<?php

declare(strict_types=1);

namespace App\UI\Http;

use App\UI\Http\Dto\HealthResponseDto;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check endpoint (liveness probe)
 */
final class HealthController
{
    /**
     * Returns the health status of the application.
     */
    #[Route('/healthz', name: 'healthcheck', methods: ['GET'])]
    #[OA\Get(
        path: '/healthz',
        summary: 'Health check (liveness probe)',
        tags: ['Health'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application OK',
                content: new OA\JsonContent(ref: new Model(type: HealthResponseDto::class))
            )
        ]
    )]
    public function __invoke(): JsonResponse
    {
        $dto = new HealthResponseDto('ok');

        return new JsonResponse($dto);
    }
}
