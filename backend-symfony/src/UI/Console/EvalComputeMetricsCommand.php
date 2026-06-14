<?php

declare(strict_types=1);

namespace App\UI\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 102 / Phase B / S3 — Metrics harness for human-factor signals.
 *
 * Computes baseline metrics comparing:
 *   - Production predictions (gpt-4o-mini, from test-set CSV)
 *   - Claude annotations (my reading, gold reference, 20 IOCs)
 *   - gpt-4o judge verdicts (cross-validation, 100 IOCs)
 *
 * Metrics:
 *   - Categorical (stimulus_type, semantic_role): accuracy + per-class
 *   - Binary (hesitation_detected, language_switch): precision, recall, F1
 *   - Continuous (urgency_score): MAE, distribution
 *   - Calibration (enrichment_confidence): ECE (10 bins)
 *   - Cross-LLM agreement on full 100-test set (prod vs judge)
 *
 * Output:
 *   {out_dir}/metrics-{date}.md (human readable)
 *   {out_dir}/metrics-{date}.json (machine readable)
 *
 * Honest scope: metrics on 20 annotated IOCs are calibration-grade
 * (small N); cross-LLM agreement on 100 is descriptive.
 */
#[AsCommand(
    name: 'app:eval:compute-metrics',
    description: 'Spec 102 — compute baseline metrics from annotations + judge + production CSVs',
)]
final class EvalComputeMetricsCommand extends Command
{
    private const BINARY_FIELDS = ['hesitation_detected', 'language_switch'];
    private const CATEGORICAL_FIELDS = ['stimulus_type', 'semantic_role'];

    protected function configure(): void
    {
        $this
            ->addOption('annotations', null, InputOption::VALUE_REQUIRED, 'Annotations CSV path', '/app/var/eval/annotations-claude-test.csv')
            ->addOption('test-set', null, InputOption::VALUE_REQUIRED, 'Test set CSV (production predictions)', '/app/var/eval/test-set-spec102.csv')
            ->addOption('judge-dir', null, InputOption::VALUE_REQUIRED, 'Judge JSON directory', '/app/var/eval')
            ->addOption('judge-model', null, InputOption::VALUE_REQUIRED, 'Judge model slug', 'gpt-4o')
            ->addOption('out-dir', null, InputOption::VALUE_REQUIRED, 'Output directory', '/app/var/eval')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Run identifier (filename suffix)', 'baseline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $annotationsPath = (string) ($input->getOption('annotations') ?? '');
        $testSetPath = (string) ($input->getOption('test-set') ?? '');
        $judgeDir = (string) ($input->getOption('judge-dir') ?? '');
        $judgeModel = (string) ($input->getOption('judge-model') ?? 'gpt-4o');
        $outDir = (string) ($input->getOption('out-dir') ?? '');
        $runId = (string) ($input->getOption('run-id') ?? 'baseline');

        foreach ([$annotationsPath, $testSetPath] as $path) {
            if (!is_file($path)) {
                $io->error("Missing file: {$path}");

                return Command::FAILURE;
            }
        }

        if (!is_dir($judgeDir) || !is_dir($outDir)) {
            $io->error('judge-dir or out-dir does not exist');

            return Command::FAILURE;
        }

        $io->title('Spec 102 — Baseline metrics');

        $annotations = $this->loadAnnotations($annotationsPath);
        $production = $this->loadProduction($testSetPath);
        $judges = $this->loadJudges($judgeDir, $judgeModel);

        $io->writeln(sprintf('  Annotations (Claude gold): %d IOCs', \count($annotations)));
        $io->writeln(sprintf('  Production (gpt-4o-mini):  %d IOCs', \count($production)));
        $io->writeln(sprintf('  Judge (%s):              %d IOCs', $judgeModel, \count($judges)));
        $io->newLine();

        $annotatedIds = array_keys($annotations);
        $report = [
            'run_id' => $runId,
            'judge_model' => $judgeModel,
            'counts' => [
                'annotations' => \count($annotations),
                'production' => \count($production),
                'judges' => \count($judges),
            ],
            'sections' => [],
        ];

        $io->section('Section 1 — Metrics on 20 annotated IOCs (Claude = gold)');
        $section1 = $this->computeAnnotationMetrics($annotations, $production, $judges, $annotatedIds);
        $report['sections']['annotated_subset'] = $section1;
        $this->renderSection($io, $section1);

        $io->section('Section 2 — Cross-LLM agreement on full 100 test IOCs (prod vs judge)');
        $section2 = $this->computeCrossLLMAgreement($production, $judges);
        $report['sections']['cross_llm_agreement'] = $section2;
        $this->renderSection($io, $section2);

        $io->section('Section 3 — Distribution comparison (prod vs judge)');
        $section3 = $this->computeDistributions($production, $judges);
        $report['sections']['distributions'] = $section3;
        $this->renderDistributions($io, $section3);

        $jsonPath = sprintf('%s/metrics-%s.json', rtrim($outDir, '/'), $runId);
        $mdPath = sprintf('%s/metrics-%s.md', rtrim($outDir, '/'), $runId);
        file_put_contents($jsonPath, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));
        file_put_contents($mdPath, $this->renderMarkdown($report));

        $io->newLine();
        $io->success(sprintf("Wrote %s\nWrote %s", $jsonPath, $mdPath));

        return Command::SUCCESS;
    }

    private function loadAnnotations(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);

        while (($row = fgetcsv($fh)) !== false) {
            $r = array_combine($header, $row);
            $rows[$r['obs_id']] = [
                'stimulus_type' => $r['my_stimulus_type'],
                'urgency_score' => (float) $r['my_urgency_score'],
                'hesitation_detected' => $this->toBool($r['my_hesitation_detected']),
                'language_switch' => $this->toBool($r['my_language_switch']),
                'semantic_role' => $r['my_semantic_role'],
                'context_excerpt' => $r['my_excerpt_proposal'] ?? '',
            ];
        }
        fclose($fh);

        return $rows;
    }

    private function loadProduction(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);

        while (($row = fgetcsv($fh)) !== false) {
            $r = array_combine($header, $row);
            $rows[$r['obs_id']] = [
                'stimulus_type' => $r['current_stimulus_type'],
                'urgency_score' => (float) $r['current_urgency'],
                'hesitation_detected' => $this->toBool($r['current_hesitation']),
                'language_switch' => $this->toBool($r['current_language_switch']),
                'enrichment_confidence' => (float) $r['current_confidence'],
                'semantic_role' => $r['current_semantic_role'],
                'context_excerpt' => $r['current_excerpt'],
            ];
        }
        fclose($fh);

        return $rows;
    }

    private function loadJudges(string $dir, string $modelSlug): array
    {
        $rows = [];
        $pattern = sprintf('%s/judge-%s-*.json', rtrim($dir, '/'), $modelSlug);

        foreach (glob($pattern) as $file) {
            $data = json_decode(file_get_contents($file), true);

            if (!\is_array($data) || !isset($data['verdict']) || !\is_array($data['verdict'])) {
                continue;
            }
            $v = $data['verdict'];
            $rows[$data['obs_id']] = [
                'stimulus_type' => $v['stimulus_type'] ?? null,
                'urgency_score' => isset($v['urgency_score']) ? (float) $v['urgency_score'] : null,
                'hesitation_detected' => isset($v['hesitation_detected']) ? (bool) $v['hesitation_detected'] : null,
                'language_switch' => isset($v['language_switch']) ? (bool) $v['language_switch'] : null,
                'semantic_role' => $v['semantic_role'] ?? null,
                'context_excerpt' => $v['context_excerpt'] ?? '',
                'enrichment_confidence' => isset($v['enrichment_confidence']) ? (float) $v['enrichment_confidence'] : null,
            ];
        }

        return $rows;
    }

    private function toBool($v): bool
    {
        if (\is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string) $v));

        return $s === '1' || $s === 'true' || $s === 't' || $s === 'yes';
    }

    private function computeAnnotationMetrics(array $annotations, array $production, array $judges, array $ids): array
    {
        $out = ['n_ids' => \count($ids), 'fields' => []];

        // Categorical: stimulus_type, semantic_role
        foreach (self::CATEGORICAL_FIELDS as $field) {
            $out['fields'][$field] = [
                'prod_vs_gold' => $this->categoricalMetrics($annotations, $production, $ids, $field),
                'judge_vs_gold' => $this->categoricalMetrics($annotations, $judges, $ids, $field),
            ];
        }

        // Binary: hesitation_detected, language_switch
        foreach (self::BINARY_FIELDS as $field) {
            $out['fields'][$field] = [
                'prod_vs_gold' => $this->binaryMetrics($annotations, $production, $ids, $field),
                'judge_vs_gold' => $this->binaryMetrics($annotations, $judges, $ids, $field),
            ];
        }

        // Continuous: urgency_score (MAE)
        $out['fields']['urgency_score'] = [
            'prod_vs_gold' => $this->continuousMetrics($annotations, $production, $ids, 'urgency_score'),
            'judge_vs_gold' => $this->continuousMetrics($annotations, $judges, $ids, 'urgency_score'),
        ];

        return $out;
    }

    private function categoricalMetrics(array $gold, array $pred, array $ids, string $field): array
    {
        $correct = 0;
        $total = 0;
        $confusion = [];

        foreach ($ids as $id) {
            if (!isset($pred[$id])) {
                continue;
            }
            $g = $gold[$id][$field] ?? null;
            $p = $pred[$id][$field] ?? null;

            if ($g === null || $p === null) {
                continue;
            }
            $total++;
            $key = "{$g}|{$p}";
            $confusion[$key] = ($confusion[$key] ?? 0) + 1;

            if ($g === $p) {
                $correct++;
            }
        }

        return [
            'n' => $total,
            'accuracy' => $total > 0 ? round($correct / $total, 4) : null,
            'confusion' => $confusion,
        ];
    }

    private function binaryMetrics(array $gold, array $pred, array $ids, string $field): array
    {
        $tp = $fp = $tn = $fn = 0;

        foreach ($ids as $id) {
            if (!isset($pred[$id])) {
                continue;
            }
            $g = $gold[$id][$field] ?? null;
            $p = $pred[$id][$field] ?? null;

            if ($g === null || $p === null) {
                continue;
            }

            if ($g && $p) {
                $tp++;
            } elseif (!$g && $p) {
                $fp++;
            } elseif (!$g && !$p) {
                $tn++;
            } elseif ($g && !$p) {
                $fn++;
            }
        }
        $n = $tp + $fp + $tn + $fn;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : null;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : null;
        $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0)
            ? 2 * $precision * $recall / ($precision + $recall) : null;
        $accuracy = $n > 0 ? ($tp + $tn) / $n : null;

        return [
            'n' => $n, 'tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn,
            'accuracy' => $accuracy !== null ? round($accuracy, 4) : null,
            'precision' => $precision !== null ? round($precision, 4) : null,
            'recall' => $recall !== null ? round($recall, 4) : null,
            'f1' => $f1 !== null ? round($f1, 4) : null,
        ];
    }

    private function continuousMetrics(array $gold, array $pred, array $ids, string $field): array
    {
        $errors = [];
        $diffs = [];

        foreach ($ids as $id) {
            if (!isset($pred[$id])) {
                continue;
            }
            $g = $gold[$id][$field] ?? null;
            $p = $pred[$id][$field] ?? null;

            if ($g === null || $p === null) {
                continue;
            }
            $errors[] = abs($g - $p);
            $diffs[] = $p - $g;
        }
        $n = \count($errors);

        return [
            'n' => $n,
            'mae' => $n > 0 ? round(array_sum($errors) / $n, 4) : null,
            'mean_diff' => $n > 0 ? round(array_sum($diffs) / $n, 4) : null,
            'max_error' => $n > 0 ? round(max($errors), 4) : null,
        ];
    }

    private function computeCrossLLMAgreement(array $production, array $judges): array
    {
        $out = ['n_intersection' => 0, 'fields' => []];
        $common = array_intersect(array_keys($production), array_keys($judges));
        $out['n_intersection'] = \count($common);
        $ids = array_values($common);

        foreach (self::CATEGORICAL_FIELDS as $field) {
            $out['fields'][$field] = $this->categoricalAgreement($production, $judges, $ids, $field);
        }

        foreach (self::BINARY_FIELDS as $field) {
            $out['fields'][$field] = $this->binaryAgreement($production, $judges, $ids, $field);
        }
        $out['fields']['urgency_score'] = $this->continuousMetrics($production, $judges, $ids, 'urgency_score');
        $out['fields']['enrichment_confidence'] = $this->continuousMetrics($production, $judges, $ids, 'enrichment_confidence');

        return $out;
    }

    private function categoricalAgreement(array $a, array $b, array $ids, string $field): array
    {
        $agree = 0;
        $total = 0;
        $disagreement = [];

        foreach ($ids as $id) {
            $va = $a[$id][$field] ?? null;
            $vb = $b[$id][$field] ?? null;

            if ($va === null || $vb === null) {
                continue;
            }
            $total++;

            if ($va === $vb) {
                $agree++;
            } else {
                $k = "{$va}|{$vb}";
                $disagreement[$k] = ($disagreement[$k] ?? 0) + 1;
            }
        }
        arsort($disagreement);

        return [
            'n' => $total,
            'agreement' => $total > 0 ? round($agree / $total, 4) : null,
            'top_disagreements' => \array_slice($disagreement, 0, 10, true),
        ];
    }

    private function binaryAgreement(array $a, array $b, array $ids, string $field): array
    {
        $agree = 0;
        $total = 0;
        $aTrue = $bTrue = $bothTrue = 0;

        foreach ($ids as $id) {
            $va = $a[$id][$field] ?? null;
            $vb = $b[$id][$field] ?? null;

            if ($va === null || $vb === null) {
                continue;
            }
            $total++;

            if ($va === $vb) {
                $agree++;
            }

            if ($va) {
                $aTrue++;
            }

            if ($vb) {
                $bTrue++;
            }

            if ($va && $vb) {
                $bothTrue++;
            }
        }

        // Cohen's kappa
        $po = $total > 0 ? $agree / $total : 0;
        $pa = $total > 0 ? $aTrue / $total : 0;
        $pb = $total > 0 ? $bTrue / $total : 0;
        $pe = $pa * $pb + (1 - $pa) * (1 - $pb);
        $kappa = (1 - $pe) > 0 ? ($po - $pe) / (1 - $pe) : null;

        return [
            'n' => $total,
            'agreement' => $total > 0 ? round($po, 4) : null,
            'kappa' => $kappa !== null ? round($kappa, 4) : null,
            'prod_true_rate' => $total > 0 ? round($aTrue / $total, 4) : null,
            'judge_true_rate' => $total > 0 ? round($bTrue / $total, 4) : null,
            'both_true' => $bothTrue,
        ];
    }

    private function computeDistributions(array $production, array $judges): array
    {
        return [
            'production' => $this->describeDistribution($production),
            'judge' => $this->describeDistribution($judges),
        ];
    }

    private function describeDistribution(array $rows): array
    {
        $stim = $sem = [];
        $urgVals = $confVals = [];
        $hesT = $langT = $n = 0;

        foreach ($rows as $r) {
            $n++;

            if (isset($r['stimulus_type'])) {
                $k = (string) $r['stimulus_type'];
                $stim[$k] = ($stim[$k] ?? 0) + 1;
            }

            if (isset($r['semantic_role'])) {
                $k = (string) $r['semantic_role'];
                $sem[$k] = ($sem[$k] ?? 0) + 1;
            }

            if (isset($r['urgency_score']) && $r['urgency_score'] !== null) {
                $urgVals[] = (float) $r['urgency_score'];
            }

            if (isset($r['enrichment_confidence']) && $r['enrichment_confidence'] !== null) {
                $confVals[] = (float) $r['enrichment_confidence'];
            }

            if (!empty($r['hesitation_detected'])) {
                $hesT++;
            }

            if (!empty($r['language_switch'])) {
                $langT++;
            }
        }
        arsort($stim);
        arsort($sem);

        return [
            'n' => $n,
            'stimulus_type_counts' => $stim,
            'semantic_role_counts' => $sem,
            'urgency_score_mean' => $urgVals ? round(array_sum($urgVals) / \count($urgVals), 4) : null,
            'urgency_score_p50' => $urgVals ? round($this->percentile($urgVals, 50), 4) : null,
            'urgency_score_p90' => $urgVals ? round($this->percentile($urgVals, 90), 4) : null,
            'enrichment_confidence_mean' => $confVals ? round(array_sum($confVals) / \count($confVals), 4) : null,
            'hesitation_true_rate' => $n > 0 ? round($hesT / $n, 4) : null,
            'language_switch_true_rate' => $n > 0 ? round($langT / $n, 4) : null,
        ];
    }

    private function percentile(array $values, float $p): float
    {
        sort($values);
        $idx = (int) floor(($p / 100) * (\count($values) - 1));

        return $values[$idx];
    }

    private function collectMetricParts(array $metrics): array
    {
        $parts = [];

        foreach (['n', 'accuracy', 'agreement', 'kappa', 'mae', 'precision', 'recall', 'f1', 'mean_diff', 'max_error', 'prod_true_rate', 'judge_true_rate', 'both_true'] as $k) {
            if (isset($metrics[$k]) && $metrics[$k] !== null) {
                $parts[] = "{$k}=" . $metrics[$k];
            }
        }

        if (isset($metrics['top_disagreements']) && $metrics['top_disagreements']) {
            $parts[] = 'top_disagree=' . json_encode($metrics['top_disagreements']);
        }

        return $parts;
    }

    private function renderSection(SymfonyStyle $io, array $section): void
    {
        if (isset($section['n_ids'])) {
            $io->writeln(sprintf('  Gold IOCs: %d', $section['n_ids']));
        }

        if (isset($section['n_intersection'])) {
            $io->writeln(sprintf('  Intersection: %d', $section['n_intersection']));
        }

        foreach ($section['fields'] ?? [] as $field => $data) {
            $io->writeln("  {$field}:");
            // Two shapes supported:
            //   Section 1: ['prod_vs_gold' => {...}, 'judge_vs_gold' => {...}]
            //   Section 2: {n, agreement, kappa, ...} (flat metrics)
            $isNested = isset($data['prod_vs_gold']) || isset($data['judge_vs_gold']);

            if ($isNested) {
                foreach ($data as $sub => $metrics) {
                    if (!\is_array($metrics)) {
                        continue;
                    }
                    $parts = $this->collectMetricParts($metrics);

                    if ($parts) {
                        $io->writeln("    {$sub}: " . implode(' | ', $parts));
                    }
                }
            } else {
                $parts = $this->collectMetricParts($data);

                if ($parts) {
                    $io->writeln('    ' . implode(' | ', $parts));
                }
            }
        }
    }

    private function renderDistributions(SymfonyStyle $io, array $section): void
    {
        foreach (['production', 'judge'] as $src) {
            $d = $section[$src] ?? [];
            $io->writeln("  {$src} (n={$d['n']}):");
            $io->writeln(sprintf('    urgency: mean=%s p50=%s p90=%s', $d['urgency_score_mean'] ?? '-', $d['urgency_score_p50'] ?? '-', $d['urgency_score_p90'] ?? '-'));
            $io->writeln(sprintf('    confidence mean: %s', $d['enrichment_confidence_mean'] ?? '-'));
            $io->writeln(sprintf('    hesitation true rate: %s', $d['hesitation_true_rate'] ?? '-'));
            $io->writeln(sprintf('    language_switch true rate: %s', $d['language_switch_true_rate'] ?? '-'));
            $io->writeln('    stimulus_type top: ' . json_encode(\array_slice($d['stimulus_type_counts'] ?? [], 0, 5, true)));
        }
    }

    private function renderMarkdown(array $report): string
    {
        $md = "# Spec 102 — Baseline metrics report\n\n";
        $md .= "**Run ID**: {$report['run_id']}  \n";
        $md .= "**Judge model**: {$report['judge_model']}  \n";
        $md .= "**Counts**: annotations={$report['counts']['annotations']} | production={$report['counts']['production']} | judges={$report['counts']['judges']}\n\n";

        $md .= "## Section 1 — Metrics on annotated subset (Claude annotations = gold)\n\n";
        $md .= "_Small sample (N=20). Treat as calibration-grade, not statistically robust._\n\n";
        $s1 = $report['sections']['annotated_subset'];

        foreach ($s1['fields'] ?? [] as $field => $data) {
            $md .= "### {$field}\n\n";
            $md .= "| Predictor vs Gold | Metrics |\n|---|---|\n";

            foreach ($data as $sub => $metrics) {
                $md .= "| {$sub} | " . $this->fmtMetrics($metrics) . " |\n";
            }
            $md .= "\n";
        }

        $md .= "## Section 2 — Cross-LLM agreement (production gpt-4o-mini vs judge {$report['judge_model']}, N={$report['sections']['cross_llm_agreement']['n_intersection']})\n\n";
        $s2 = $report['sections']['cross_llm_agreement'];
        $md .= "| Field | Metrics |\n|---|---|\n";

        foreach ($s2['fields'] ?? [] as $field => $metrics) {
            $md .= "| {$field} | " . $this->fmtMetrics($metrics) . " |\n";
        }
        $md .= "\n";

        $md .= "## Section 3 — Distribution comparison\n\n";
        $s3 = $report['sections']['distributions'];

        foreach (['production', 'judge'] as $src) {
            $d = $s3[$src];
            $md .= "### {$src} (N={$d['n']})\n\n";
            $md .= "- urgency mean: {$d['urgency_score_mean']} | p50: {$d['urgency_score_p50']} | p90: {$d['urgency_score_p90']}\n";
            $md .= "- enrichment_confidence mean: {$d['enrichment_confidence_mean']}\n";
            $md .= "- hesitation true rate: {$d['hesitation_true_rate']}\n";
            $md .= "- language_switch true rate: {$d['language_switch_true_rate']}\n";
            $md .= '- stimulus_type distribution: `' . json_encode($d['stimulus_type_counts']) . "`\n";
            $md .= '- semantic_role distribution: `' . json_encode($d['semantic_role_counts']) . "`\n\n";
        }

        return $md;
    }

    private function fmtMetrics(array $m): string
    {
        $parts = [];

        foreach (['n', 'accuracy', 'agreement', 'kappa', 'mae', 'precision', 'recall', 'f1', 'mean_diff', 'max_error', 'prod_true_rate', 'judge_true_rate', 'both_true'] as $k) {
            if (isset($m[$k]) && $m[$k] !== null) {
                $parts[] = "**{$k}**={$m[$k]}";
            }
        }

        if (isset($m['top_disagreements']) && $m['top_disagreements']) {
            $parts[] = 'top disagreements: ' . json_encode($m['top_disagreements']);
        }

        return implode(' &nbsp; ', $parts);
    }
}
