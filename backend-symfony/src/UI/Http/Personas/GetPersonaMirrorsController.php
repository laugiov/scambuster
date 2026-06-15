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
 * Spec 104 P3 — Cognitive Mirror endpoints.
 *
 * Two GET routes:
 *   - /personas/{persona_code}/mirrors  → all scam types for one persona
 *   - /scam-types/{code}/mirrors         → all personas for one scam type
 *
 * Read-only. Empty arrays when the cache hasn't been filled yet —
 * the frontend renders a "generation pending" empty state.
 */
final readonly class GetPersonaMirrorsController
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
    public function byPersona(string $personaCode): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $personaCode,
                'mirrors' => $this->service->getByPersona($personaCode),
            ],
        ], Response::HTTP_OK);
    }

    #[OA\Get(
        path: '/api/v1/scam-types/{code}/mirrors',
        summary: 'Cognitive mirror text for one scam type across all personas',
        tags: ['Personas'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Mirror data'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/scam-types/{code}/mirrors', name: 'api_scam_types_mirrors', methods: ['GET'])]
    #[IsGranted('monitoring:read')]
    public function byScamType(string $code): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => [
                'scam_type_code' => $code,
                'mirrors' => $this->service->getByScamType($code),
            ],
        ], Response::HTTP_OK);
    }
}
