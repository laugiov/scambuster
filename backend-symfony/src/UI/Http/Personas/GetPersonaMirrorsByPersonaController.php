<?php

declare(strict_types=1);

namespace App\UI\Http\Personas;

use App\Application\Scambaiting\PersonaMirrorQueryService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cognitive Mirror text for one persona across all scam types.
 *
 * Read-only. Returns an empty array when the cache hasn't been filled yet —
 * the frontend renders a "generation pending" empty state.
 */
final readonly class GetPersonaMirrorsByPersonaController
{
    public function __construct(
        private PersonaMirrorQueryService $service,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/personas/{personaCode}/mirrors',
        summary: 'Cognitive mirror text for one persona across all scam types',
        tags: ['Personas'],
        parameters: [
            new OA\Parameter(name: 'personaCode', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mirror data'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/personas/{personaCode}/mirrors', name: 'api_personas_mirrors', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function __invoke(string $personaCode): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $personaCode,
                'mirrors' => $this->service->getByPersona($personaCode),
            ],
        ], Response::HTTP_OK);
    }
}
