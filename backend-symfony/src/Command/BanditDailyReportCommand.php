<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Scambaiting\BanditConvergenceLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bandit:daily-report',
    description: 'Log daily convergence snapshot for each active scam type',
)]
class BanditDailyReportCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        /** @var list<array{scam_type_id: int|string, code: string}> $scamTypes */
        $scamTypes = $conn->fetchAllAssociative(
            "SELECT scam_type_id, code FROM lkp_scam_type WHERE active = true AND code != 'UNKNOWN'"
        );

        if (\count($scamTypes) === 0) {
            $io->warning('No active scam types found.');

            return Command::SUCCESS;
        }

        $logged = 0;

        foreach ($scamTypes as $row) {
            $code = (string) $row['code'];
            $scamTypeId = (int) $row['scam_type_id'];

            $result = $this->computeConvergence($conn, $scamTypeId);

            $entry = new BanditConvergenceLog(
                scamTypeCode: $code,
                dominantPersonaCode: $result['dominant_persona'],
                dominantPct: $result['dominant_pct'],
                sessionsCount: $result['total_sessions'],
                converged: $result['converged'],
            );

            $this->em->persist($entry);
            $logged++;

            $status = $result['converged'] ? 'CONVERGED' : 'exploring';
            $io->text(sprintf(
                '  %s: %s at %.1f%% (%d sessions) [%s]',
                $code,
                $result['dominant_persona'],
                $result['dominant_pct'] * 100,
                $result['total_sessions'],
                $status,
            ));
        }

        $this->em->flush();

        $io->success(sprintf('Logged convergence for %d scam types.', $logged));
        $this->logger->info('[BanditDailyReport] Convergence snapshot logged', ['count' => $logged]);

        return Command::SUCCESS;
    }

    /**
     * @return array{dominant_persona: string, dominant_pct: float, total_sessions: int, converged: bool}
     */
    private function computeConvergence(Connection $conn, int $scamTypeId): array
    {
        /** @var list<array{persona_id: int|string, sessions_count: int|string, reward_avg: string}> $stats */
        $stats = $conn->fetchAllAssociative(
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

        // Resolve persona code
        /** @var string|false $personaCode */
        $personaCode = $conn->fetchOne(
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
}
