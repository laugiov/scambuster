<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Application\Scambaiting\PersonaOptimizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Retourne les statistiques de sélection de persona pour un scam_type donné.
 * Utile pour monitoring et debugging.
 */
#[Route('/api/v1/scambaiting/stats/{scamTypeCode}', name: 'api_scambaiting_stats', methods: ['GET'])]
final class GetScambaitingStatsController extends AbstractController
{
    public function __construct(
        private readonly PersonaOptimizer $personaOptimizer
    ) {
    }

    public function __invoke(string $scamTypeCode): JsonResponse
    {
        $stats = $this->personaOptimizer->getSelectionStats($scamTypeCode);

        if (isset($stats['error'])) {
            return new JsonResponse($stats, Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $stats,
        ], Response::HTTP_OK);
    }
}
