<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Guard\CanaryBaselineException;
use App\Application\Guard\CanaryBaselineProvider;
use App\Application\Guard\PromptCanaryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GUARD merge-gate (CLI façade): score a candidate smoke summary against the frozen baseline
 * and exit non-zero on any safety/behaviour regression. This is the deterministic decision
 * only — it calls no LLM. The expensive real-LLM smoke that produces the summary is run
 * separately (see the `guard` make target: smoke → this command), so the gate stays fast,
 * offline, and unit-testable, and a CI/pre-merge step can consume its exit code directly.
 *
 * The baseline is the gate's trust anchor: if a .sha256 companion sits next to it, its
 * integrity is verified first, so a hand-edited baseline (which would silently weaken the
 * gate) fails CLOSED rather than quietly lowering the bar.
 */
#[AsCommand(
    name: 'scambuster:guard:check',
    description: 'Merge-gate: diff a candidate smoke summary against the frozen baseline; exit non-zero on regression.',
)]
final class GuardCheckCommand extends Command
{
    private const DEFAULT_BASELINE = 'tests/Smoke/guard-baseline.json';

    public function __construct(
        private readonly PromptCanaryService $canary,
        private readonly CanaryBaselineProvider $baselineProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('summary-json', null, InputOption::VALUE_REQUIRED, 'Path to the candidate smoke summary JSON (scambuster:smoke:reply-objective --summary-json)')
            ->addOption('baseline', null, InputOption::VALUE_OPTIONAL, 'Path to the frozen baseline JSON', self::DEFAULT_BASELINE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $summaryOpt = $input->getOption('summary-json');
        $summaryPath = is_string($summaryOpt) ? $summaryOpt : '';
        $baselineOpt = $input->getOption('baseline');
        $baselinePath = is_string($baselineOpt) ? $baselineOpt : self::DEFAULT_BASELINE;

        $summary = $this->readJsonObject($io, $summaryPath, 'summary');

        if ($summary === null) {
            return Command::FAILURE;
        }

        if (!isset($summary['fixtures'], $summary['aggregate'])) {
            $io->error("Not a smoke summary (missing fixtures/aggregate): {$summaryPath}");

            return Command::FAILURE;
        }

        try {
            $baseline = $this->baselineProvider->load($baselinePath);
        } catch (CanaryBaselineException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $verdict = $this->canary->evaluate($summary, $baseline);

        $this->renderVerdict($io, $verdict, $summaryPath, $baselinePath);

        return $verdict['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonObject(SymfonyStyle $io, string $path, string $label): ?array
    {
        if ($path === '' || !is_file($path)) {
            $io->error("Cannot find {$label} JSON (pass a valid path): {$path}");

            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            $io->error("Cannot read {$label} JSON: {$path}");

            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error("Invalid JSON in {$label} ({$path}): {$e->getMessage()}");

            return null;
        }

        if (!is_array($decoded)) {
            $io->error("{$label} JSON is not an object: {$path}");

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array{ok: bool, fingerprint_ok: bool, regressions: list<array{signal: string, baseline: float, candidate: float, delta: float, reason: string}>, candidate: array<string, mixed>} $verdict
     */
    private function renderVerdict(SymfonyStyle $io, array $verdict, string $summaryPath, string $baselinePath): void
    {
        $io->section('GUARD merge-gate');
        $io->writeln("  candidate: {$summaryPath}");
        $io->writeln("  baseline:  {$baselinePath}");
        $io->newLine();

        if ($verdict['ok']) {
            $io->success('No regression — candidate is within tolerance of the baseline.');

            return;
        }

        $rows = [];

        foreach ($verdict['regressions'] as $r) {
            $rows[] = [
                $r['signal'],
                $this->fmt($r['baseline']),
                $this->fmt($r['candidate']),
                sprintf('%+.4f', $r['delta']),
                $r['reason'],
            ];
        }

        $io->table(['signal', 'baseline', 'candidate', 'delta', 'reason'], $rows);
        $io->error(sprintf('REGRESSION — %d signal(s) breached. Gate FAILED.', \count($verdict['regressions'])));
    }

    private function fmt(float $value): string
    {
        return sprintf('%.4f', $value);
    }
}
