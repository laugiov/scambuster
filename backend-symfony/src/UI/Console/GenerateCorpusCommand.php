<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Evaluation\CorpusGenerator;
use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:evaluate:generate-corpus',
    description: 'Generate an evaluation corpus by calling the real LLM pipeline on conversations',
)]
final class GenerateCorpusCommand extends Command
{
    public function __construct(
        private readonly CorpusGenerator $corpusGenerator,
        private readonly JsonReportWriter $jsonWriter,
        private readonly MarkdownReportWriter $mdWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of replies to generate', '500')
            ->addOption('scam-type', 's', InputOption::VALUE_REQUIRED, 'Filter by scam type code')
            ->addOption('persona', 'p', InputOption::VALUE_REQUIRED, 'Filter by persona code')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Filter by detected language (en, fr)')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds between API calls', '1.0')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'var/evaluation')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Estimate cost without calling LLM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $rawCount */
        $rawCount = $input->getOption('count');
        $count = (int) $rawCount;
        /** @var string $rawSleep */
        $rawSleep = $input->getOption('sleep');
        $sleep = (float) $rawSleep;
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');

        $filters = array_filter([
            'scam_type' => $input->getOption('scam-type'),
            'persona' => $input->getOption('persona'),
            'language' => $input->getOption('language'),
        ]);

        $io->title('Corpus Generation' . ($dryRun ? ' [DRY RUN]' : ''));
        $io->text(sprintf('Target: %d entries, Sleep: %.1fs, Filters: %s', $count, $sleep, json_encode($filters) ?: '{}'));

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        $result = $this->corpusGenerator->generate(
            filters: $filters,
            count: $count,
            sleep: $sleep,
            dryRun: $dryRun,
            onProgress: function (int $current, int $total) use ($progressBar): void {
                $progressBar->setProgress($current);
            },
        );

        $progressBar->finish();
        $io->newLine(2);

        $timestamp = date('Ymd-His');
        $corpusPath = $outputDir . '/corpus-' . $timestamp . '.json';
        $summaryPath = $outputDir . '/corpus-' . $timestamp . '-summary.md';

        $this->jsonWriter->write([
            'metadata' => $result['summary'],
            'entries' => $result['entries'],
        ], $corpusPath);

        $this->mdWriter->writeCorpusSummary($result['summary'], $summaryPath);

        /** @var array{total: int, approved: int, fallback: int, total_cost: float, personas: array<string, int>, scam_types: array<string, int>, languages: array<string, int>} $summary */
        $summary = $result['summary'];
        $io->success(sprintf(
            'Generated %d entries (approved: %d, fallback: %d, cost: $%.4f)',
            $summary['total'],
            $summary['approved'],
            $summary['fallback'],
            $summary['total_cost'],
        ));

        $io->table(
            ['Metric', 'Value'],
            [
                ['Total entries', (string) $summary['total']],
                ['Personas', (string) count($summary['personas'])],
                ['Scam types', (string) count($summary['scam_types'])],
                ['Languages', (string) count($summary['languages'])],
                ['Estimated cost', '$' . number_format($summary['total_cost'], 4)],
            ],
        );

        $io->text([
            'Corpus: ' . $corpusPath,
            'Summary: ' . $summaryPath,
        ]);

        return Command::SUCCESS;
    }
}
