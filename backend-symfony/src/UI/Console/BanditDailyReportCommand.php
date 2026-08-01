<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Scambaiting\BanditConvergenceReporter;
use App\Domain\Scambaiting\BanditConvergenceLog;
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
        private readonly BanditConvergenceReporter $reporter,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $scamTypes = $this->reporter->fetchActiveScamTypes();

        if ($scamTypes === []) {
            $io->warning('No active scam types found.');

            return Command::SUCCESS;
        }

        $logged = 0;

        foreach ($scamTypes as $row) {
            $code = (string) $row['code'];
            $scamTypeId = (int) $row['scam_type_id'];

            $result = $this->reporter->computeConvergence($scamTypeId);

            $entry = new BanditConvergenceLog(
                scamTypeCode: $code,
                dominantPersonaCode: $result['dominant_persona'],
                dominantPct: $result['dominant_pct'],
                sessionsCount: $result['total_sessions'],
                converged: $result['converged'],
            );

            $this->reporter->persistLog($entry);
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

        $this->reporter->flush();

        $io->success(sprintf('Logged convergence for %d scam types.', $logged));
        $this->logger->info('[BanditDailyReport] Convergence snapshot logged', ['count' => $logged]);

        return Command::SUCCESS;
    }
}
