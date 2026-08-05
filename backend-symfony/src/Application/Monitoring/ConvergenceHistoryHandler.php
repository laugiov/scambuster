<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ConvergenceHistoryHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getHistory(int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        /** @var list<BanditConvergenceLog> $logs */
        $logs = $this->em->createQueryBuilder()
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

        return [
            'period_days' => $days,
            'by_scam_type' => (object) $grouped,
        ];
    }
}
