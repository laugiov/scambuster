<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\PersonaManager;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Get(
    path: '/api/v1/personas/{personaCode}',
    summary: 'Get full persona detail including system prompt',
    tags: ['Personas'],
    parameters: [
        new OA\Parameter(name: 'personaCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'senior_trusting')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Persona detail'),
        new OA\Response(response: 404, description: 'Persona not found'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/personas/{personaCode}', name: 'api_persona_get', methods: ['GET'])]
#[IsGranted('conversation:read')]
final class GetPersonaController extends AbstractController
{
    public function __construct(
        private readonly PersonaManager $personaManager
    ) {
    }

    public function __invoke(string $personaCode): JsonResponse
    {
        $persona = $this->personaManager->findByCode($personaCode);

        if ($persona === null) {
            return new JsonResponse([
                'success' => false,
                'error' => "Persona '{$personaCode}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $persona->getPersonaCode(),
                'persona_label' => $persona->getPersonaLabel(),
                'persona_tone' => $persona->getPersonaTone(),
                'system_prompt' => $persona->getSystemPrompt(),
                'is_active' => $persona->isActive(),
                'created_by' => $persona->getCreatedBy(),
                'created_at' => $persona->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
