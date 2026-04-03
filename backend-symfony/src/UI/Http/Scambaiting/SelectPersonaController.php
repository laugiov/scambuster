<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint de test pour sélectionner un persona via l'algorithme ε-greedy.
 * Utile pour debugging et validation du comportement de l'algorithme.
 */
#[OA\Post(
    path: '/api/v1/scambaiting/select-persona',
    summary: 'Select a persona using the epsilon-greedy algorithm',
    tags: ['Scambaiting'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['scam_type_code'],
            properties: [
                new OA\Property(property: 'scam_type_code', type: 'string', example: 'PHISHING'),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Persona selected successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(
                        property: 'data',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'selected_persona', type: 'string'),
                            new OA\Property(property: 'strategy', type: 'string', example: 'exploit'),
                            new OA\Property(property: 'scam_type_code', type: 'string'),
                            new OA\Property(property: 'selection_context', type: 'object', description: 'Full selection stats (same as GET /stats/{scamTypeCode})'),
                        ]
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 400,
            description: 'Missing or invalid scam_type_code',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string'),
                ]
            )
        ),
        new OA\Response(
            response: 500,
            description: 'No persona could be selected',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: false),
                    new OA\Property(property: 'error', type: 'string'),
                ]
            )
        ),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/scambaiting/select-persona', name: 'api_scambaiting_select_persona', methods: ['POST'])]
#[IsGranted('conversation:write')]
final class SelectPersonaController extends AbstractController
{
    public function __construct(
        private readonly PersonaOptimizer $personaOptimizer
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Valider le payload
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['scam_type_code']) || !is_string($data['scam_type_code'])) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing or invalid scam_type_code',
            ], Response::HTTP_BAD_REQUEST);
        }

        $scamTypeCode = $data['scam_type_code'];

        // 2. Sélectionner le persona avec stratégie
        $selection = $this->personaOptimizer->selectPersonaWithStrategy($scamTypeCode);

        if ($selection['persona_code'] === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No persona selected (no active personas found)',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // 3. Récupérer les stats de sélection (pour contexte)
        $stats = $this->personaOptimizer->getSelectionStats($scamTypeCode);

        return new JsonResponse([
            'success' => true,
            'data' => [
                'selected_persona' => $selection['persona_code'],
                'strategy' => $selection['strategy'],
                'scam_type_code' => $scamTypeCode,
                'selection_context' => $stats,
            ],
        ], Response::HTTP_OK);
    }
}
