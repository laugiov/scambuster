<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Endpoint de test pour sélectionner un persona via l'algorithme ε-greedy.
 * Utile pour debugging et validation du comportement de l'algorithme.
 */
#[Route('/api/v1/scambaiting/select-persona', name: 'api_scambaiting_select_persona', methods: ['POST'])]
final class SelectPersonaController extends AbstractController
{
    public function __construct(
        private readonly PersonaOptimizer $personaOptimizer
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Valider le payload
        $data = json_decode($request->getContent(), true);

        if (!isset($data['scam_type_code']) || !is_string($data['scam_type_code'])) {
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
