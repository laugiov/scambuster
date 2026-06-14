<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 102 / Phase B / S0 — Stratified sampler for human-factor evaluation.
 *
 * Selects 150 enriched IOC contexts from the production DB, stratified to
 * over-sample the rare classes that the audit (phase-a-field-audit.md)
 * flagged as targets:
 *   - hesitation_detected = true   (≥ 25)
 *   - language_switch = true       (≥ 25)
 *   - stimulus_type ≠ PASSIVE      (≥ 25, stratified across the 5 non-PASSIVE classes)
 *   - enrichment_confidence ≥ 0.7  (≥ 30)
 *   - remainder filled at random   (target total = 150)
 *
 * Then splits 50 train / 100 test deterministically (via seed) so the test
 * set is FROZEN — every preflight gate after this command runs checks that
 * the SHA256 of the test-set file is unchanged.
 *
 * Output:
 *   - {out_dir}/test-set-spec102.csv      (100 rows, fields: obs_id, indicator_id, msg_id, conv_id, current_*)
 *   - {out_dir}/train-set-spec102.csv     (50  rows, same shape)
 *   - {out_dir}/test-set-spec102.sha256
 *   - {out_dir}/train-set-spec102.sha256
 *
 * Default out-dir is `backend-symfony/var/eval/` (git-ignored).
 *
 * Reproducibility chain:
 *   - this file (committed) defines the stratification + shuffle algorithm
 *   - --seed flag (default 42) seeds mt_srand for deterministic selection
 *   - the dev DB state at the time of sampling is the data input
 *
 * Reference SHA256 captured 2026-06-14 against dev DB (4934 enriched rows):
 *   test-set-spec102.csv  = 1f32bb406cdfd80705d2c8d6b909572907a98058cb53350a76d9fe5030c694af
 *   train-set-spec102.csv = dca0021891c624326de49c71b050bc3bac0bbe20285d9af5cde5d1356a3e8493
 *
 * Phase D (baseline) and Phase E (interventions) MUST run against these
 * exact SHA256s. Any divergence means the sample changed and conclusions
 * are no longer comparable to the baseline.
 */
#[AsCommand(
    name: 'app:eval:sample-test-set',
    description: 'Spec 102 — stratified sample of 150 IOC contexts (train 50 / test 100) for human-factor calibration evaluation',
)]
final class EvalSampleTestSetCommand extends Command
{
    private const TARGETS = [
        'hesitation' => 25,
        'language_switch' => 25,
        'non_passive' => 25, // stratified across 5 sub-types
        'high_confidence' => 30,
    ];
    private const TOTAL_TARGET = 150;
    private const TRAIN_SIZE = 50;
    private const TEST_SIZE = 100;

    public function __construct(
        private readonly Connection $conn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed for reproducibility', '42')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Output directory (default: backend-symfony/var/eval)', '/app/var/eval');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $seed = (int) ($input->getOption('seed') ?? '42');
        $outDir = (string) ($input->getOption('out-dir') ?? '/app/var/eval');

        if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
            $io->error("Cannot create out-dir: {$outDir}");

            return Command::FAILURE;
        }

        mt_srand($seed);
        $io->title('Spec 102 — Stratified test-set sampler');
        $io->writeln("  seed: {$seed}");
        $io->writeln("  out-dir: {$outDir}");
        $io->newLine();

        // ─── 1. Pool sampling per stratum ─────────────────────────────
        $io->section('Phase 1 — Stratified sampling');
        $pool = [];

        $hesitationIds = $this->sampleStratum(
            "SELECT obs_id FROM ioc_context WHERE enrichment_status='enriched' AND hesitation_detected = true",
            self::TARGETS['hesitation'],
        );
        $this->merge($pool, $hesitationIds);
        $io->writeln(sprintf('  hesitation_detected=true     → %d obs_ids', \count($hesitationIds)));

        $langSwitchIds = $this->sampleStratum(
            "SELECT obs_id FROM ioc_context WHERE enrichment_status='enriched' AND language_switch = true",
            self::TARGETS['language_switch'],
        );
        $this->merge($pool, $langSwitchIds);
        $io->writeln(sprintf('  language_switch=true         → %d obs_ids', \count($langSwitchIds)));

        // non-PASSIVE stimulus: balance across 5 sub-types
        $nonPassiveSubtypes = ['URGENCY_PRESSURE', 'TRUST_BUILDING', 'DIRECT_REQUEST', 'DOCUMENT_REQUEST', 'PAYMENT_INITIATION'];
        $perSubtype = (int) ceil(self::TARGETS['non_passive'] / \count($nonPassiveSubtypes));
        $nonPassiveAccum = [];

        foreach ($nonPassiveSubtypes as $subtype) {
            $ids = $this->sampleStratum(
                "SELECT obs_id FROM ioc_context WHERE enrichment_status='enriched' AND stimulus_type = '{$subtype}'",
                $perSubtype,
            );
            $nonPassiveAccum = array_merge($nonPassiveAccum, $ids);
        }
        $this->merge($pool, $nonPassiveAccum);
        $io->writeln(sprintf('  stimulus_type≠PASSIVE        → %d obs_ids (stratified across 5)', \count($nonPassiveAccum)));

        $highConfIds = $this->sampleStratum(
            "SELECT obs_id FROM ioc_context WHERE enrichment_status='enriched' AND enrichment_confidence >= 0.7",
            self::TARGETS['high_confidence'],
        );
        $this->merge($pool, $highConfIds);
        $io->writeln(sprintf('  enrichment_confidence ≥ 0.7  → %d obs_ids', \count($highConfIds)));

        // ─── 2. Fill remainder at random ──────────────────────────────
        $remaining = self::TOTAL_TARGET - \count($pool);

        if ($remaining > 0) {
            $excludeList = "'" . implode("','", array_keys($pool)) . "'";
            $fillerIds = $this->sampleStratum(
                "SELECT obs_id FROM ioc_context WHERE enrichment_status='enriched' AND obs_id NOT IN ({$excludeList})",
                $remaining,
            );
            $this->merge($pool, $fillerIds);
            $io->writeln(sprintf('  random filler                → %d obs_ids', \count($fillerIds)));
        }

        $io->newLine();
        $io->writeln(sprintf('  TOTAL pool: %d distinct obs_ids', \count($pool)));

        if (\count($pool) < self::TOTAL_TARGET) {
            $io->warning(sprintf(
                'Pool has only %d rows; targets require %d. Some strata may be under-populated. Continuing with available rows.',
                \count($pool),
                self::TOTAL_TARGET,
            ));
        }

        // ─── 3. Hydrate metadata for each obs_id ──────────────────────
        $io->section('Phase 2 — Hydrating IOC metadata');
        $rows = $this->hydrate(array_keys($pool));
        $io->writeln(sprintf('  hydrated %d rows', \count($rows)));

        // ─── 4. Deterministic shuffle + split ─────────────────────────
        $io->section('Phase 3 — Train/test split');
        // Sort by obs_id then shuffle with our seeded RNG so the split is
        // deterministic for a given (seed, dataset) combination.
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['obs_id'], (string) $b['obs_id']));
        $this->seededShuffle($rows);

        $testRows = \array_slice($rows, 0, self::TEST_SIZE);
        $trainRows = \array_slice($rows, self::TEST_SIZE, self::TRAIN_SIZE);

        $io->writeln(sprintf('  train: %d rows', \count($trainRows)));
        $io->writeln(sprintf('  test:  %d rows', \count($testRows)));

        // Gate 11 assertion (train/test isolation)
        $trainIds = array_column($trainRows, 'obs_id');
        $testIds = array_column($testRows, 'obs_id');
        $overlap = array_intersect($trainIds, $testIds);

        if (\count($overlap) > 0) {
            $io->error('FATAL: train and test sets overlap on ' . \count($overlap) . ' obs_ids. Aborting.');

            return Command::FAILURE;
        }

        // ─── 5. Write CSVs + SHA256 ───────────────────────────────────
        $io->section('Phase 4 — Writing outputs');
        $testPath = rtrim($outDir, '/') . '/test-set-spec102.csv';
        $trainPath = rtrim($outDir, '/') . '/train-set-spec102.csv';
        $this->writeCsv($testPath, $testRows);
        $this->writeCsv($trainPath, $trainRows);

        $testSha = hash_file('sha256', $testPath);
        $trainSha = hash_file('sha256', $trainPath);
        file_put_contents($testPath . '.sha256', "{$testSha}  test-set-spec102.csv\n");
        file_put_contents($trainPath . '.sha256', "{$trainSha}  train-set-spec102.csv\n");

        $io->writeln('  ' . $testPath);
        $io->writeln('  ' . $testPath . '.sha256');
        $io->writeln('  ' . $trainPath);
        $io->writeln('  ' . $trainPath . '.sha256');
        $io->newLine();
        $io->success(sprintf(
            'Sampled %d obs_ids (test=%d, train=%d) with seed=%d. SHA256 test=%s',
            \count($pool),
            \count($testRows),
            \count($trainRows),
            $seed,
            substr($testSha, 0, 16) . '…',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function sampleStratum(string $sql, int $target): array
    {
        $all = $this->conn->fetchFirstColumn($sql);

        if (\count($all) <= $target) {
            return array_map('strval', $all);
        }
        // Reservoir would be cleaner but pool is small; shuffle + slice is fine.
        $this->seededShuffle($all);

        return array_map('strval', \array_slice($all, 0, $target));
    }

    /**
     * @param array<string, true> $pool
     * @param list<string>        $ids
     */
    private function merge(array &$pool, array $ids): void
    {
        foreach ($ids as $id) {
            $pool[$id] = true;
        }
    }

    /**
     * @param list<string> $obsIds
     *
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $obsIds): array
    {
        if ($obsIds === []) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT
                ic.obs_id,
                ic.indicator_id,
                oi.msg_id,
                m.conv_id,
                ic.stimulus_type      AS current_stimulus_type,
                ic.urgency_score      AS current_urgency,
                ic.hesitation_detected AS current_hesitation,
                ic.language_switch    AS current_language_switch,
                ic.enrichment_confidence AS current_confidence,
                ic.semantic_role      AS current_semantic_role,
                ic.context_excerpt    AS current_excerpt
             FROM ioc_context ic
             JOIN observed_ioc oi ON oi.obs_id = ic.obs_id
             JOIN message m ON m.msg_id = oi.msg_id
             WHERE ic.obs_id IN (?)',
            [$obsIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return $rows;
    }

    /**
     * Fisher–Yates with our seeded mt_rand for reproducibility.
     *
     * @param array<int, mixed> $a
     */
    private function seededShuffle(array &$a): void
    {
        $n = \count($a);

        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $a[$i];
            $a[$i] = $a[$j];
            $a[$j] = $tmp;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        if ($rows === []) {
            file_put_contents($path, '');

            return;
        }
        $fh = fopen($path, 'w');

        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$path} for writing");
        }
        $header = array_keys($rows[0]);
        fputcsv($fh, $header);

        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn ($v) => $v ?? '', $row));
        }
        fclose($fh);
    }
}
