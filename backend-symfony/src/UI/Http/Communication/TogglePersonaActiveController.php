<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\PersonaManager;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[OA\Patch(
    path: '/api/v1/personas/{personaCode}/active',
    summary: 'Activate or deactivate a persona',
    tags: ['Personas'],
    parameters: [
        new OA\Parameter(name: 'personaCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'senior_trusting')),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(
            required: ['active'],
            properties: [
                new OA\Property(property: 'active', type: 'boolean'),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Persona status updated'),
        new OA\Response(response: 404, description: 'Persona not found'),
        new OA\Response(response: 422, description: 'Invalid request body'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/personas/{personaCode}/active', name: 'api_persona_toggle_active', methods: ['PATCH'])]
final class TogglePersonaActiveController extends AbstractController
{
    public function __construct(
        private readonly PersonaManager $personaManager
    ) {
    }

    public function __invoke(Request $request, string $personaCode): JsonResponse
    {
        $persona = $this->personaManager->findByCode($personaCode);

        if ($persona === null) {
            return new JsonResponse([
                'success' => false,
                'error' => "Persona '{$personaCode}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);

        if (!is_array($body) || !array_key_exists('active', $body) || !is_bool($body['active'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Request body must contain "active" (boolean)',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($body['active']) {
            $this->personaManager->activate($persona);
        } else {
            $this->personaManager->deactivate($persona);
        }

        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $persona->getPersonaCode(),
                'is_active' => $persona->isActive(),
            ],
        ]);
    }
}
