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
 * Cognitive Mirror text for one scam type across all personas.
 *
 * Read-only. Returns an empty array when the cache hasn't been filled yet —
 * the frontend renders a "generation pending" empty state.
 */
final readonly class GetPersonaMirrorsByScamTypeController
{
    public function __construct(
        private PersonaMirrorQueryService $service,
    ) {
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
    public function __invoke(string $code): JsonResponse
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
