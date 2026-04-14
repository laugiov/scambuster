<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Evaluation\ReplyQualityAnalyzer;
use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:evaluate:reply-quality',
    description: 'Evaluate reply quality from a generated corpus file',
)]
final class EvaluateReplyQualityCommand extends Command
{
    public function __construct(
        private readonly ReplyQualityAnalyzer $analyzer,
        private readonly JsonReportWriter $jsonWriter,
        private readonly MarkdownReportWriter $mdWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('corpus-file', InputArgument::REQUIRED, 'Path to corpus JSON file')
            ->addOption('output-dir', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'var/evaluation')
            ->addOption('min-samples', null, InputOption::VALUE_REQUIRED, 'Minimum samples for valid metric', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $corpusFile */
        $corpusFile = $input->getArgument('corpus-file');
        /** @var string $outputDir */
        $outputDir = $input->getOption('output-dir');

        if (!file_exists($corpusFile)) {
            $io->error('Corpus file not found: ' . $corpusFile);

            return Command::FAILURE;
        }

        $io->title('Reply Quality Evaluation');

        $raw = file_get_contents($corpusFile);

        if ($raw === false) {
            $io->error('Cannot read corpus file');

            return Command::FAILURE;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            $io->error('Invalid JSON in corpus file');

            return Command::FAILURE;
        }

        $corpus = $data['entries'] ?? $data;

        if (empty($corpus)) {
            $io->error('Corpus is empty');

            return Command::FAILURE;
        }

        $io->text(sprintf('Analyzing %d corpus entries...', count($corpus)));

        $result = $this->analyzer->analyze($corpus);

        $timestamp = date('Ymd-His');
        $jsonPath = $outputDir . '/quality-report-' . $timestamp . '.json';
        $mdPath = $outputDir . '/quality-report-' . $timestamp . '.md';

        $this->jsonWriter->write([
            'overall_verdict' => $result['overall_verdict'],
            'corpus_size' => $result['corpus_size'],
            'metrics' => array_map(fn ($m): array => $m->toArray(), $result['metrics']),
            'best_replies' => $result['best_replies'],
            'worst_replies' => $result['worst_replies'],
            'generated_at' => date(\DATE_ATOM),
        ], $jsonPath);

        $this->mdWriter->writeQualityReport(
            $result['metrics'],
            $result['best_replies'],
            $result['worst_replies'],
            $result['persona_matrix'],
            $result['overall_verdict'],
            $result['corpus_size'],
            $mdPath,
        );

        $io->section('Results');
        $rows = [];

        foreach ($result['metrics'] as $m) {
            $cmp = $m->comparison === 'lt' ? '<' : '>';
            $rows[] = [
                $m->name,
                $m->dimension,
                sprintf('%.2f', $m->measuredValue),
                $cmp . ' ' . sprintf('%.2f', $m->targetThreshold),
                $m->verdict,
                (string) $m->sampleSize,
            ];
        }

        $io->table(['Metric', 'Dimension', 'Value', 'Target', 'Verdict', 'Samples'], $rows);
        $io->text('Overall verdict: **' . $result['overall_verdict'] . '**');
        $io->newLine();
        $io->text([
            'JSON report: ' . $jsonPath,
            'Markdown report: ' . $mdPath,
        ]);

        return $result['overall_verdict'] === 'FAIL' ? Command::FAILURE : Command::SUCCESS;
    }
}
