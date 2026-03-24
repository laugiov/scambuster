<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/monitoring/convergence-history', name: 'monitoring_convergence_history', methods: ['GET'])]
final class ConvergenceHistoryController
{
    public function __invoke(EntityManagerInterface $em): JsonResponse
    {
        $since = new \DateTimeImmutable('-30 days');

        /** @var list<BanditConvergenceLog> $logs */
        $logs = $em->createQueryBuilder()
            ->select('l')
            ->from(BanditConvergenceLog::class, 'l')
            ->where('l.loggedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('l.loggedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($logs as $log) {
            $grouped[$log->getScamTypeCode()][] = [
                'date' => $log->getLoggedAt()->format('Y-m-d'),
                'dominant_persona' => $log->getDominantPersonaCode(),
                'dominant_pct' => $log->getDominantPct(),
                'sessions_count' => $log->getSessionsCount(),
                'converged' => $log->isConverged(),
            ];
        }

        return new JsonResponse([
            'period_days' => 30,
            'by_scam_type' => (object) $grouped,
        ]);
    }
}
