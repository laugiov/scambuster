<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes and persists daily bandit convergence snapshots for each active scam type.
 */
final readonly class BanditConvergenceReporter
{
    private Connection $connection;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
        $this->connection = $em->getConnection();
    }

    /**
     * @return list<array{scam_type_id: int|string, code: string}>
     */
    public function fetchActiveScamTypes(): array
    {
        /** @var list<array{scam_type_id: int|string, code: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT scam_type_id, code FROM lkp_scam_type WHERE active = true AND code != 'UNKNOWN'"
        );

        return $rows;
    }

    /**
     * @return array{dominant_persona: string, dominant_pct: float, total_sessions: int, converged: bool}
     */
    public function computeConvergence(int $scamTypeId): array
    {
        /** @var list<array{persona_id: int|string, sessions_count: int|string, reward_avg: string}> $stats */
        $stats = $this->connection->fetchAllAssociative(
            'SELECT pps.persona_id, pps.sessions_count, pps.reward_avg
             FROM persona_performance_stats pps
             WHERE pps.scam_type_id = :id',
            ['id' => $scamTypeId]
        );

        $totalSessions = 0;
        $bestPersonaId = null;
        $bestSessions = 0;

        foreach ($stats as $stat) {
            $sessions = (int) $stat['sessions_count'];
            $totalSessions += $sessions;

            if ($sessions > $bestSessions) {
                $bestSessions = $sessions;
                $bestPersonaId = (int) $stat['persona_id'];
            }
        }

        if ($totalSessions === 0 || $bestPersonaId === null) {
            return [
                'dominant_persona' => 'none',
                'dominant_pct' => 0.0,
                'total_sessions' => 0,
                'converged' => false,
            ];
        }

        $dominantPct = $bestSessions / $totalSessions;

        /** @var string|false $personaCode */
        $personaCode = $this->connection->fetchOne(
            'SELECT persona_code FROM persona WHERE persona_id = :id',
            ['id' => $bestPersonaId]
        );

        return [
            'dominant_persona' => \is_string($personaCode) ? $personaCode : "persona_{$bestPersonaId}",
            'dominant_pct' => round($dominantPct, 4),
            'total_sessions' => $totalSessions,
            'converged' => $dominantPct >= 0.60 && $totalSessions >= 10,
        ];
    }

    public function persistLog(BanditConvergenceLog $entry): void
    {
        $this->em->persist($entry);
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
