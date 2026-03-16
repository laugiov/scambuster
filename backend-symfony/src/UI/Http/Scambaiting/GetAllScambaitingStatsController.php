<?php

declare(strict_types=1);

namespace App\UI\Http\Scambaiting;

use App\Infrastructure\Doctrine\Repository\PersonaPerformanceStatsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Retourne les statistiques agrégées pour tous les scam_types.
 * Affiche un aperçu global des performances.
 */
#[Route('/api/v1/scambaiting/stats', name: 'api_scambaiting_all_stats', methods: ['GET'])]
final class GetAllScambaitingStatsController extends AbstractController
{
    public function __construct(
        private readonly PersonaPerformanceStatsRepository $statsRepository
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $aggregatedStats = $this->statsRepository->getAggregatedStatsByScamType();

        return new JsonResponse([
            'success' => true,
            'data' => $aggregatedStats,
        ], Response::HTTP_OK);
    }
}
