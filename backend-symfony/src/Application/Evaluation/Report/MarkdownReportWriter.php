<?php

declare(strict_types=1);

namespace App\Application\Evaluation\Report;

use App\Application\Evaluation\Metric\MetricResult;

/**
 * Writes evaluation reports as human-readable Markdown files.
 */
final class MarkdownReportWriter
{
    /**
     * Write a quality report to Markdown.
     *
     * @param MetricResult[]                      $metrics
     * @param array<int, array<string, mixed>>    $bestReplies
     * @param array<int, array<string, mixed>>    $worstReplies
     * @param array<string, array<string, float>> $personaMatrix
     */
    public function writeQualityReport(
        array $metrics,
        array $bestReplies,
        array $worstReplies,
        array $personaMatrix,
        string $overallVerdict,
        int $corpusSize,
        string $outputPath,
    ): void {
        $lines = [];
        $lines[] = '# Reply Quality Evaluation Report';
        $lines[] = '';
        $lines[] = '**Generated**: ' . date('Y-m-d H:i:s T');
        $lines[] = '**Corpus size**: ' . $corpusSize . ' entries';
        $lines[] = '**Overall verdict**: **' . $overallVerdict . '**';
        $lines[] = '';

        // Metrics table
        $lines[] = '## Metrics Summary';
        $lines[] = '';
        $lines[] = '| Metric | Dimension | Value | Target | Verdict |';
        $lines[] = '|--------|-----------|-------|--------|---------|';

        foreach ($metrics as $m) {
            $cmp = $m->comparison === 'lt' ? '<' : '>';
            $badge = $m->verdict === 'PASS' ? 'PASS' : ($m->verdict === 'FAIL' ? '**FAIL**' : '_' . $m->verdict . '_');
            $lines[] = sprintf(
                '| %s | %s | %.2f | %s %.2f | %s |',
                $m->name,
                $m->dimension,
                $m->measuredValue,
                $cmp,
                $m->targetThreshold,
                $badge,
            );
        }

        $lines[] = '';

        // Best replies
        if ($bestReplies !== []) {
            $lines[] = '## Top 5 Best Replies (by naturalness)';
            $lines[] = '';

            foreach ($bestReplies as $i => $entry) {
                /** @var string $personaCode */
                $personaCode = $entry['persona_code'] ?? '?';
                /** @var string $scamType */
                $scamType = $entry['scam_type'] ?? '?';
                /** @var string $entryText */
                $entryText = $entry['text'] ?? '';
                $lines[] = '### #' . ($i + 1) . ' — ' . $personaCode . ' / ' . $scamType;
                $lines[] = '> ' . str_replace("\n", "\n> ", mb_substr($entryText, 0, 200));
                $lines[] = '';
                /** @var int $nat */
                $nat = $entry['naturalness'] ?? 0;
                /** @var int $pfit */
                $pfit = $entry['persona_fit'] ?? 0;
                /** @var int $tiVal */
                $tiVal = $entry['ti_value'] ?? 0;
                /** @var int $wc */
                $wc = $entry['word_count'] ?? 0;
                $lines[] = sprintf(
                    'Naturalness: %d | Persona fit: %d | TI value: %d | Words: %d',
                    $nat,
                    $pfit,
                    $tiVal,
                    $wc,
                );
                $lines[] = '';
            }
        }

        // Worst replies
        if ($worstReplies !== []) {
            $lines[] = '## Bottom 5 Worst Replies (by naturalness)';
            $lines[] = '';

            foreach ($worstReplies as $i => $entry) {
                /** @var string $personaCode */
                $personaCode = $entry['persona_code'] ?? '?';
                /** @var string $scamType */
                $scamType = $entry['scam_type'] ?? '?';
                /** @var string $entryText */
                $entryText = $entry['text'] ?? '';
                $lines[] = '### #' . ($i + 1) . ' — ' . $personaCode . ' / ' . $scamType;
                $lines[] = '> ' . str_replace("\n", "\n> ", mb_substr($entryText, 0, 200));
                $lines[] = '';
                /** @var int $nat */
                $nat = $entry['naturalness'] ?? 0;
                /** @var int $pfit */
                $pfit = $entry['persona_fit'] ?? 0;
                /** @var int $tiVal */
                $tiVal = $entry['ti_value'] ?? 0;
                /** @var int $wc */
                $wc = $entry['word_count'] ?? 0;
                $lines[] = sprintf(
                    'Naturalness: %d | Persona fit: %d | TI value: %d | Words: %d',
                    $nat,
                    $pfit,
                    $tiVal,
                    $wc,
                );
                $lines[] = '';
            }
        }

        // Persona similarity matrix
        if ($personaMatrix !== []) {
            $lines[] = '## Persona Similarity Matrix';
            $lines[] = '';

            $personas = array_keys($personaMatrix);
            $header = '| | ' . implode(' | ', $personas) . ' |';
            $separator = '|---' . str_repeat('|---', count($personas)) . '|';
            $lines[] = $header;
            $lines[] = $separator;

            foreach ($personas as $p1) {
                $row = "| {$p1}";

                foreach ($personas as $p2) {
                    $row .= sprintf(' | %.2f', $personaMatrix[$p1][$p2] ?? 0.0);
                }

                $row .= ' |';
                $lines[] = $row;
            }

            $lines[] = '';
        }

        $this->writeFile($outputPath, $lines);
    }

    /**
     * Write a bandit analysis report to Markdown.
     *
     * @param array<string, mixed> $report
     */
    public function writeBanditReport(array $report, string $outputPath): void
    {
        $lines = [];
        $lines[] = '# Bandit Convergence Analysis Report';
        $lines[] = '';
        $lines[] = '**Generated**: ' . date('Y-m-d H:i:s T');
        /** @var int $totalConv */
        $totalConv = $report['total_conversations'] ?? 0;
        $lines[] = '**Total conversations analyzed**: ' . $totalConv;
        $lines[] = '**Overall convergence**: ' . (($report['overall_convergence'] ?? false) ? 'YES' : 'NO');
        $lines[] = '';

        if (!empty($report['scam_type_analyses'])) {
            $lines[] = '## Per Scam Type Convergence';
            $lines[] = '';
            $lines[] = '| Scam Type | Sessions | Dominant Persona | Share | Converged |';
            $lines[] = '|-----------|----------|------------------|-------|-----------|';

            /** @var array<int, array<string, mixed>> $scamTypeAnalyses */
            $scamTypeAnalyses = $report['scam_type_analyses'];

            foreach ($scamTypeAnalyses as $analysis) {
                /** @var float $domPct */
                $domPct = $analysis['dominant_percentage'] ?? 0;
                /** @var string $aScamType */
                $aScamType = $analysis['scam_type'] ?? '?';
                /** @var int $aSessionsCount */
                $aSessionsCount = $analysis['sessions_count'] ?? 0;
                /** @var string $aDomPersona */
                $aDomPersona = $analysis['dominant_persona'] ?? '?';
                $lines[] = sprintf(
                    '| %s | %d | %s | %.0f%% | %s |',
                    $aScamType,
                    $aSessionsCount,
                    $aDomPersona,
                    $domPct * 100,
                    ($analysis['converged'] ?? false) ? 'YES' : 'no',
                );
            }

            $lines[] = '';
        }

        $lines[] = '## Regret Analysis';
        $lines[] = '';
        /** @var float $cumRegret */
        $cumRegret = $report['cumulative_regret'] ?? 0;
        /** @var float $randBaseline */
        $randBaseline = $report['random_baseline_regret'] ?? 0;
        $lines[] = sprintf('- Cumulative regret (vs oracle): %.2f', $cumRegret);
        $lines[] = sprintf('- Random baseline regret: %.2f', $randBaseline);

        $regretReduction = 0.0;
        /** @var float $randomBaselineRegret */
        $randomBaselineRegret = $report['random_baseline_regret'] ?? 0;
        /** @var float $cumulativeRegret */
        $cumulativeRegret = $report['cumulative_regret'] ?? 0;

        if ($randomBaselineRegret > 0) {
            $regretReduction = (1 - $cumulativeRegret / $randomBaselineRegret) * 100;
        }

        $lines[] = sprintf('- Regret reduction vs random: %.1f%%', $regretReduction);
        $lines[] = '';

        $this->writeFile($outputPath, $lines);
    }

    /**
     * Write a corpus generation summary to Markdown.
     *
     * @param array<string, mixed> $summary
     */
    public function writeCorpusSummary(array $summary, string $outputPath): void
    {
        $lines = [];
        $lines[] = '# Corpus Generation Summary';
        $lines[] = '';
        $lines[] = '**Generated**: ' . date('Y-m-d H:i:s T');
        /** @var int $sumTotal */
        $sumTotal = $summary['total'] ?? 0;
        /** @var int $sumApproved */
        $sumApproved = $summary['approved'] ?? 0;
        /** @var int $sumFallback */
        $sumFallback = $summary['fallback'] ?? 0;
        /** @var float $sumCost */
        $sumCost = $summary['total_cost'] ?? 0;
        $lines[] = '**Total entries**: ' . $sumTotal;
        $lines[] = '**Approved**: ' . $sumApproved;
        $lines[] = '**Fallback used**: ' . $sumFallback;
        $lines[] = '**Estimated cost**: $' . number_format($sumCost, 4);
        $lines[] = '';

        if (!empty($summary['personas'])) {
            $lines[] = '## Persona Distribution';
            $lines[] = '';
            $lines[] = '| Persona | Count |';
            $lines[] = '|---------|-------|';

            /** @var array<string, int> $personas */
            $personas = $summary['personas'];

            foreach ($personas as $persona => $count) {
                $lines[] = "| {$persona} | {$count} |";
            }

            $lines[] = '';
        }

        if (!empty($summary['scam_types'])) {
            $lines[] = '## Scam Type Distribution';
            $lines[] = '';
            $lines[] = '| Scam Type | Count |';
            $lines[] = '|-----------|-------|';

            /** @var array<string, int> $scamTypeDist */
            $scamTypeDist = $summary['scam_types'];

            foreach ($scamTypeDist as $type => $count) {
                $lines[] = "| {$type} | {$count} |";
            }

            $lines[] = '';
        }

        if (!empty($summary['languages'])) {
            $lines[] = '## Language Distribution';
            $lines[] = '';
            $lines[] = '| Language | Count |';
            $lines[] = '|----------|-------|';

            /** @var array<string, int> $langDist */
            $langDist = $summary['languages'];

            foreach ($langDist as $lang => $count) {
                $lines[] = "| {$lang} | {$count} |";
            }

            $lines[] = '';
        }

        $this->writeFile($outputPath, $lines);
    }

    /**
     * @param array<string> $lines
     */
    private function writeFile(string $outputPath, array $lines): void
    {
        $dir = dirname($outputPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, implode("\n", $lines) . "\n");
    }
}
