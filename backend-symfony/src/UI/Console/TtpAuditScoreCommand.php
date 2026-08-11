<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Ttp\Audit\AuditReportRenderer;
use App\Application\Ttp\Audit\AuditRunContext;
use App\Application\Ttp\Audit\AuditScoreCalculator;
use App\Application\Ttp\Audit\AuditSheetReader;
use App\Domain\Communication\Ttp;
use App\Domain\Communication\TtpTaxonomySeed;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compute the figures of a TTP extraction quality audit from a scored sheet.
 *
 * This is the second half of the audit that `scambuster:ttp:audit-sample` starts.
 * That command exports the sheet WITH raw evidence, because a human scoring
 * precision has to read what the model tagged. This one reads the sheet back after
 * two people have scored it, and turns the verdict columns into raw agreement,
 * Cohen's kappa, overall precision and per-code counts.
 *
 * The evidence column never reaches the output. The command reads verdict labels,
 * taxonomy codes and the observation status; the markdown it prints is meant to be
 * pasted into the public results document under docs/, and there is no path by
 * which scammer text could travel with it (Constitution III).
 *
 * The sheet has to be complete before its figures mean anything: every row
 * double-scored, every row adjudicated, every disagreement carrying a written
 * reason. Structural gaps are printed above the figures and, unless --force is
 * passed, the command exits non-zero so an unfinished sheet cannot quietly become
 * a published number.
 *
 * No figure produced here is extrapolated to the corpus and none carries a
 * confidence interval: what the sample says, it says about the sample
 * (Spec 001 FR-007, Constitution I).
 */
#[AsCommand(
    name: 'scambuster:ttp:audit-score',
    description: 'Compute precision, raw agreement and Cohen\'s kappa from a scored TTP audit sheet.',
)]
final class TtpAuditScoreCommand extends Command
{
    private const CODEBOOK_VERSION = '1.0.0';

    public function __construct(
        private readonly AuditSheetReader $reader,
        private readonly AuditScoreCalculator $calculator,
        private readonly AuditReportRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sheet', InputArgument::REQUIRED, 'Path to the scored CSV (the exported sample plus the codebook v1 scoring columns).')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the markdown report to this file. Without it the report goes to stdout.', null)
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'The seed the sample was drawn with, recorded in the report header.', null)
            ->addOption('draw', null, InputOption::VALUE_REQUIRED, 'The draw mode the sample was exported with (uniform or stratified).', null)
            ->addOption('codebook', null, InputOption::VALUE_REQUIRED, 'Codebook version the scoring ran under.', self::CODEBOOK_VERSION)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Print the figures even when the sheet has structural problems. The problems are still listed.')
            ->setHelp(
                "Reads a scored audit sheet and computes the figures Spec 001 publishes.\n\n".
                "Expected columns, on top of the exported sample: verdict_a, verdict_b,\n".
                "verdict_final, adjudication_reason, flag, notes. See docs/standards/ttp-codebook-v1.md.\n\n".
                "The output carries no verbatim evidence and is safe to paste into a public document.\n".
                'An incomplete sheet exits non-zero unless --force is passed.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sheetPath = $input->getArgument('sheet');

        if (!\is_string($sheetPath) || $sheetPath === '') {
            $this->style($input, $output, false)->error('A path to the scored sheet is required.');

            return Command::INVALID;
        }

        $outputPathRaw = $input->getOption('output');
        $outputPath = \is_string($outputPathRaw) && $outputPathRaw !== '' ? $outputPathRaw : null;

        // With no --output the report itself streams to stdout, so the notes are
        // routed to stderr and a piped run yields clean markdown.
        $io = $this->style($input, $output, $outputPath === null);

        try {
            $sheet = $this->reader->read($sheetPath);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $force = $input->getOption('force') === true;

        if (!$sheet->isComplete()) {
            $io->warning(sprintf('The scored sheet has %d structural problem(s):', \count($sheet->problems)));
            $io->listing(array_slice($sheet->problems, 0, 50));

            if (\count($sheet->problems) > 50) {
                $io->note(sprintf('%d further problem(s) not listed.', \count($sheet->problems) - 50));
            }

            if (!$force) {
                $io->error('Refusing to compute figures from an incomplete sheet. Fix the rows above, or pass --force to see the partial figures.');

                return Command::FAILURE;
            }

            $io->note('--force given: the figures below are computed from an incomplete sheet and must not be published as they stand.');
        }

        $result = $this->calculator->calculate($sheet->rows);

        $seedRaw = $input->getOption('seed');
        $drawRaw = $input->getOption('draw');
        $codebookRaw = $input->getOption('codebook');

        $context = new AuditRunContext(
            seed: \is_string($seedRaw) ? $seedRaw : '',
            draw: \is_string($drawRaw) ? $drawRaw : '',
            taxonomyVersion: Ttp::TAXONOMY_VERSION,
            codebookVersion: \is_string($codebookRaw) && $codebookRaw !== '' ? $codebookRaw : self::CODEBOOK_VERSION,
            sheetName: basename($sheetPath),
            scoredOn: date('Y-m-d'),
            taxonomyCodes: TtpTaxonomySeed::codes(),
        );

        $report = $this->renderer->render($result, $context);

        if ($outputPath !== null) {
            if (file_put_contents($outputPath, $report) === false) {
                $io->error(sprintf('Unable to write the report to %s', $outputPath));

                return Command::FAILURE;
            }
        } else {
            $output->write($report);
        }

        $this->logger->info('[TtpAuditScore] Audit sheet scored', [
            'rows' => $result->totalRows,
            'scored' => $result->scoredRows,
            'unsamplable' => $result->unsamplableRows,
            'disagreements' => \count($result->disagreements),
            'complete' => $sheet->isComplete(),
        ]);

        $io->newLine();
        $io->success(sprintf(
            'Scored %d row(s)%s.',
            $result->scoredRows,
            $outputPath !== null ? sprintf(', report written to %s', $outputPath) : '',
        ));
        $io->note('Paste the report into docs/standards/ttp-extraction-quality.md. Keep the scored sheet internal: it carries raw evidence.');

        return $sheet->isComplete() ? Command::SUCCESS : Command::FAILURE;
    }

    private function style(InputInterface $input, OutputInterface $output, bool $notesToStderr): SymfonyStyle
    {
        $target = $output;

        if ($notesToStderr && $output instanceof ConsoleOutputInterface) {
            $target = $output->getErrorOutput();
        }

        return new SymfonyStyle($input, $target);
    }
}
