<?php

declare(strict_types=1);

namespace App\UI\Http\Meta;

use App\Application\Meta\ConfigHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Returns all reference/configuration data for the frontend in a single call.
 * Personas, scam types, IOC types, and bandit algorithm parameters.
 */
#[OA\Get(
    path: '/api/v1/meta/config',
    summary: 'Frontend configuration: personas, scam types, IOC types, bandit config',
    tags: ['Meta'],
    responses: [
        new OA\Response(response: 200, description: 'Configuration data'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/meta/config', name: 'api_meta_config', methods: ['GET'])]
final class ConfigController
{
    public function __construct(
        private readonly ConfigHandler $handler
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getConfig(), Response::HTTP_OK);
    }
}
