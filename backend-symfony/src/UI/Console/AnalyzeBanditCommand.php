<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Evaluation\BanditAnalyzer;
use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:evaluate:bandit-analysis',
    description: 'Analyze epsilon-greedy persona selection convergence per scam type',
)]
final class AnalyzeBanditCommand extends Command
{
    public function __construct(
        private readonly BanditAnalyzer $analyzer,
        private readonly JsonReportWriter $jsonWriter,
        private readonly MarkdownReportWriter $mdWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('min-sessions', null, InputOption::VALUE_REQUIRED, 'Minimum sessions per scam type', '3')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'var/evaluation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $rawMinSessions */
        $rawMinSessions = $input->getOption('min-sessions');
        $minSessions = (int) $rawMinSessions;
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');

        $io->title('Bandit Convergence Analysis');

        $report = $this->analyzer->analyze($minSessions);

        $timestamp = date('Ymd-His');
        $jsonPath = $outputDir . '/bandit-report-' . $timestamp . '.json';
        $mdPath = $outputDir . '/bandit-report-' . $timestamp . '.md';

        $this->jsonWriter->write($report, $jsonPath);
        $this->mdWriter->writeBanditReport($report, $mdPath);

        /** @var array{total_conversations: int, active_scam_types: int, convergence_rate: float, overall_convergence: bool, cumulative_regret: float, scam_type_analyses: array<int, array<string, mixed>>} $report */
        $io->section('Summary');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total conversations', (string) $report['total_conversations']],
                ['Active scam types', (string) $report['active_scam_types']],
                ['Convergence rate', sprintf('%.0f%%', $report['convergence_rate'] * 100)],
                ['Overall convergence', $report['overall_convergence'] ? 'YES' : 'NO'],
                ['Cumulative regret', sprintf('%.4f', $report['cumulative_regret'])],
            ],
        );

        if (!empty($report['scam_type_analyses'])) {
            $io->section('Per Scam Type');
            $rows = [];

            foreach ($report['scam_type_analyses'] as $a) {
                /** @var float $domPct */
                $domPct = $a['dominant_percentage'] ?? 0;
                /** @var string $aScamType */
                $aScamType = $a['scam_type'] ?? '?';
                /** @var int $aSessions */
                $aSessions = $a['sessions_count'] ?? 0;
                /** @var string $aDominant */
                $aDominant = $a['dominant_persona'] ?? '?';
                $rows[] = [
                    $aScamType,
                    (string) $aSessions,
                    $aDominant,
                    sprintf('%.0f%%', $domPct * 100),
                    ($a['converged'] ?? false) ? 'YES' : 'no',
                ];
            }

            $io->table(['Scam Type', 'Sessions', 'Dominant', 'Share', 'Converged'], $rows);
        }

        $io->text([
            'JSON report: ' . $jsonPath,
            'Markdown report: ' . $mdPath,
        ]);

        return Command::SUCCESS;
    }
}
