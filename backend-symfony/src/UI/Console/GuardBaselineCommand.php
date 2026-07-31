<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Guard\CanaryAggregate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GUARD-a: freeze a canary baseline from a smoke summary JSON. Reads the machine-readable
 * summary produced by `scambuster:smoke:reply-objective --summary-json`, scores it through
 * the safety oracle ({@see CanaryAggregate}), and writes the stable aggregate + a .sha256
 * companion to a git-tracked file. The comparator (GUARD-b) diffs a candidate run against it.
 *
 * The baseline drops volatile out-texts and keeps only rates, so it is stable and reviewable;
 * regenerating it is an explicit, reviewed commit — the gate never auto-updates it.
 */
#[AsCommand(
    name: 'scambuster:guard:baseline',
    description: 'Freeze a canary baseline (stable metrics + safety-violation rates) from a smoke summary JSON.',
)]
final class GuardBaselineCommand extends Command
{
    private const DEFAULT_BASELINE = 'tests/Smoke/guard-baseline.json';

    public function __construct(
        private readonly CanaryAggregate $aggregate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('summary-json', null, InputOption::VALUE_REQUIRED, 'Path to the smoke summary JSON (scambuster:smoke:reply-objective --summary-json)')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'Baseline output path', self::DEFAULT_BASELINE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $summaryOpt = $input->getOption('summary-json');
        $summaryPath = is_string($summaryOpt) ? $summaryOpt : '';
        $outOpt = $input->getOption('out');
        $outPath = is_string($outOpt) ? $outOpt : self::DEFAULT_BASELINE;

        if ($summaryPath === '' || !is_file($summaryPath)) {
            $io->error("Summary JSON not found (pass --summary-json): {$summaryPath}");

            return Command::FAILURE;
        }

        $raw = file_get_contents($summaryPath);

        if ($raw === false) {
            $io->error("Cannot read summary JSON: {$summaryPath}");

            return Command::FAILURE;
        }

        try {
            $summary = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error("Invalid JSON in {$summaryPath}: {$e->getMessage()}");

            return Command::FAILURE;
        }

        if (!is_array($summary) || !isset($summary['fixtures'], $summary['aggregate'])) {
            $io->error("Not a smoke summary (missing fixtures/aggregate): {$summaryPath}");

            return Command::FAILURE;
        }

        $baseline = $this->aggregate->build($summary);
        $json = json_encode($baseline, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) . "\n";

        $dir = dirname($outPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $io->error("Failed to create baseline dir: {$dir}");

            return Command::FAILURE;
        }

        if (file_put_contents($outPath, $json) === false) {
            $io->error("Failed to write baseline: {$outPath}");

            return Command::FAILURE;
        }

        $sha = hash('sha256', $json);
        file_put_contents($outPath . '.sha256', "{$sha}  " . basename($outPath) . "\n");

        $io->success("Baseline frozen: {$outPath}");
        $io->writeln("  sha256: {$sha}");
        $io->writeln(sprintf(
            '  scored %d out-texts across %d recording slots (%d runs, %d errors)',
            $baseline['meta']['out_texts_scored'],
            $baseline['meta']['recording_slots'],
            $baseline['meta']['runs'],
            $baseline['meta']['errors'],
        ));
        $io->writeln('  oracle fingerprint: ' . $baseline['meta']['oracle_fingerprint']);

        return Command::SUCCESS;
    }
}
