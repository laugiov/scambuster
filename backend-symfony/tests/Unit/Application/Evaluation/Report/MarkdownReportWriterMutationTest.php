<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Report;

use App\Application\Evaluation\Metric\MetricResult;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-killing tests for MarkdownReportWriter.
 *
 * Targets:
 * - Report heading starts with #
 * - Summary section present
 * - Metric table format (| delimiters, column headers)
 * - File extension .md
 * - Empty data produces minimal valid report
 * - Bandit report structure
 * - Corpus summary structure
 * - Best/worst reply sections
 * - Persona matrix formatting
 * - Regret calculation
 * - Convergence YES/NO text
 * - Date formatting
 * - Corpus cost formatting
 */
final class MarkdownReportWriterMutationTest extends TestCase
{
    private string $tmpDir;
    private MarkdownReportWriter $writer;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/md_mut_' . uniqid();
        $this->writer = new MarkdownReportWriter();
    }

    protected function tearDown(): void
    {
        // Recursively clean up
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        if ($items !== false) {
            foreach ($items as $item) {
                is_dir($item) ? $this->removeDir($item) : unlink($item);
            }
        }
        rmdir($dir);
    }

    private function readReport(string $filename): string
    {
        $path = $this->tmpDir . '/' . $filename;
        $content = file_get_contents($path);
        $this->assertIsString($content);
        return $content;
    }

    // === Quality report: heading ===

    public function test_quality_report_starts_with_heading(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $content = $this->readReport('q.md');
        $this->assertStringStartsWith('# Reply Quality Evaluation Report', $content);
    }

    // === Quality report: Generated date ===

    public function test_quality_report_contains_generated_date(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**Generated**:', $content);
    }

    // === Quality report: corpus size ===

    public function test_quality_report_contains_corpus_size(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 42, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('42 entries', $content);
    }

    // === Quality report: overall verdict ===

    public function test_quality_report_contains_verdict_pass(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**PASS**', $content);
    }

    public function test_quality_report_contains_verdict_fail(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'FAIL', 0, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**FAIL**', $content);
    }

    // === Metrics summary section ===

    public function test_quality_report_has_metrics_summary_heading(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('test_m', 'dim', 0.5, 0.6, 'gt', 20, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 20, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('## Metrics Summary', $content);
    }

    public function test_metrics_table_has_header_row(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('test_m', 'dim', 0.5, 0.6, 'gt', 20, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 20, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('| Metric | Dimension | Value | Target | Verdict |', $content);
    }

    public function test_metrics_table_has_separator_row(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('test_m', 'dim', 0.5, 0.6, 'gt', 20, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 20, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('|--------|-----------|-------|--------|---------|', $content);
    }

    public function test_metric_name_in_table(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('non_repetitiveness', 'diversity', 0.25, 0.30, 'lt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('non_repetitiveness', $content);
    }

    public function test_metric_lt_comparison_shows_less_than(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.25, 0.30, 'lt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('< 0.30', $content);
    }

    public function test_metric_gt_comparison_shows_greater_than(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.5, 0.6, 'gt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('> 0.60', $content);
    }

    public function test_metric_pass_verdict_shown(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.1, 0.30, 'lt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('PASS', $content);
    }

    public function test_metric_fail_verdict_bold(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.9, 0.6, 'gt', 5, 'detail')]; // sampleSize=5 < minSampleSize=10 => INSUFFICIENT
        // Use sampleSize >= minSampleSize and make it FAIL
        $metrics = [new MetricResult('m', 'd', 0.3, 0.6, 'gt', 50, 'detail')]; // 0.3 not > 0.6 => FAIL
        $this->writer->writeQualityReport($metrics, [], [], [], 'FAIL', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**FAIL**', $content);
    }

    // === Best/worst replies ===

    public function test_best_replies_section_present(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [['persona_code' => 'elderly', 'scam_type' => 'PHISHING', 'text' => 'Good reply', 'naturalness' => 5, 'persona_fit' => 4, 'ti_value' => 3, 'word_count' => 50]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('Top 5 Best Replies', $content);
    }

    public function test_worst_replies_section_present(): void
    {
        $path = $this->tmpDir . '/q.md';
        $worst = [['persona_code' => 'tech', 'scam_type' => 'ROMANCE', 'text' => 'Bad reply', 'naturalness' => 1, 'persona_fit' => 1, 'ti_value' => 1, 'word_count' => 10]];
        $this->writer->writeQualityReport([], [], $worst, [], 'FAIL', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('Bottom 5 Worst Replies', $content);
    }

    public function test_best_reply_shows_persona_and_scam_type(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [['persona_code' => 'elderly', 'scam_type' => 'PHISHING', 'text' => 'Good reply text here', 'naturalness' => 5, 'persona_fit' => 4, 'ti_value' => 3, 'word_count' => 50]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('PHISHING', $content);
    }

    public function test_reply_scores_formatted(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [['persona_code' => 'p', 'scam_type' => 's', 'text' => 'text', 'naturalness' => 5, 'persona_fit' => 4, 'ti_value' => 3, 'word_count' => 99]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('Naturalness: 5', $content);
        $this->assertStringContainsString('Persona fit: 4', $content);
        $this->assertStringContainsString('TI value: 3', $content);
        $this->assertStringContainsString('Words: 99', $content);
    }

    public function test_no_best_replies_section_when_empty(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringNotContainsString('Top 5 Best', $content);
    }

    public function test_no_worst_replies_section_when_empty(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringNotContainsString('Bottom 5 Worst', $content);
    }

    // === Persona matrix ===

    public function test_persona_matrix_section_present(): void
    {
        $path = $this->tmpDir . '/q.md';
        $matrix = ['elderly' => ['elderly' => 1.0, 'tech' => 0.3], 'tech' => ['elderly' => 0.3, 'tech' => 1.0]];
        $this->writer->writeQualityReport([], [], [], $matrix, 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('Persona Similarity Matrix', $content);
    }

    public function test_persona_matrix_contains_values(): void
    {
        $path = $this->tmpDir . '/q.md';
        $matrix = ['a' => ['a' => 1.0, 'b' => 0.42], 'b' => ['a' => 0.42, 'b' => 1.0]];
        $this->writer->writeQualityReport([], [], [], $matrix, 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('1.00', $content);
        $this->assertStringContainsString('0.42', $content);
    }

    public function test_no_persona_matrix_when_empty(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringNotContainsString('Persona Similarity Matrix', $content);
    }

    // === Bandit report ===

    public function test_bandit_report_heading(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 0, 'overall_convergence' => false, 'cumulative_regret' => 0, 'random_baseline_regret' => 0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringStartsWith('# Bandit Convergence Analysis Report', $content);
    }

    public function test_bandit_report_convergence_yes(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 10, 'overall_convergence' => true, 'cumulative_regret' => 1.0, 'random_baseline_regret' => 5.0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('YES', $content);
    }

    public function test_bandit_report_convergence_no(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 10, 'overall_convergence' => false, 'cumulative_regret' => 1.0, 'random_baseline_regret' => 5.0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('NO', $content);
    }

    public function test_bandit_report_total_conversations(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 42, 'overall_convergence' => false, 'cumulative_regret' => 0, 'random_baseline_regret' => 0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('42', $content);
    }

    public function test_bandit_report_regret_section(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 10, 'overall_convergence' => false, 'cumulative_regret' => 1.5, 'random_baseline_regret' => 5.0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('Regret Analysis', $content);
        $this->assertStringContainsString('1.50', $content);
        $this->assertStringContainsString('5.00', $content);
    }

    public function test_bandit_regret_reduction_percentage(): void
    {
        $path = $this->tmpDir . '/b.md';
        // regretReduction = (1 - 1.0/5.0) * 100 = 80.0%
        $this->writer->writeBanditReport(['total_conversations' => 10, 'overall_convergence' => false, 'cumulative_regret' => 1.0, 'random_baseline_regret' => 5.0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('80.0%', $content);
    }

    public function test_bandit_regret_reduction_zero_baseline(): void
    {
        $path = $this->tmpDir . '/b.md';
        // When baseline=0, reduction=0.0%
        $this->writer->writeBanditReport(['total_conversations' => 0, 'overall_convergence' => false, 'cumulative_regret' => 0, 'random_baseline_regret' => 0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('0.0%', $content);
    }

    public function test_bandit_scam_type_table(): void
    {
        $path = $this->tmpDir . '/b.md';
        $report = [
            'total_conversations' => 10,
            'overall_convergence' => false,
            'cumulative_regret' => 1.0,
            'random_baseline_regret' => 5.0,
            'scam_type_analyses' => [
                ['scam_type' => 'PHISHING', 'sessions_count' => 15, 'dominant_persona' => 'elderly', 'dominant_percentage' => 0.80, 'converged' => true],
            ],
        ];
        $this->writer->writeBanditReport($report, $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('Per Scam Type Convergence', $content);
        $this->assertStringContainsString('PHISHING', $content);
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('80%', $content);
        $this->assertStringContainsString('YES', $content);
    }

    // === Corpus summary ===

    public function test_corpus_summary_heading(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 0, 'approved' => 0, 'fallback' => 0, 'total_cost' => 0], $path);
        $content = $this->readReport('c.md');
        $this->assertStringStartsWith('# Corpus Generation Summary', $content);
    }

    public function test_corpus_summary_total_entries(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 500, 'approved' => 480, 'fallback' => 20, 'total_cost' => 1.5], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('500', $content);
        $this->assertStringContainsString('480', $content);
        $this->assertStringContainsString('20', $content);
    }

    public function test_corpus_summary_cost_formatted(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 1.5], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('$1.5000', $content);
    }

    public function test_corpus_summary_persona_distribution(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 0, 'personas' => ['elderly' => 5, 'tech' => 5]], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('Persona Distribution', $content);
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('5', $content);
    }

    public function test_corpus_summary_scam_type_distribution(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 0, 'scam_types' => ['PHISHING' => 7, 'ROMANCE' => 3]], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('Scam Type Distribution', $content);
        $this->assertStringContainsString('PHISHING', $content);
    }

    public function test_corpus_summary_language_distribution(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 0, 'languages' => ['fr' => 8, 'en' => 2]], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('Language Distribution', $content);
        $this->assertStringContainsString('fr', $content);
    }

    public function test_corpus_summary_no_persona_section_when_empty(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 0, 'approved' => 0, 'fallback' => 0, 'total_cost' => 0, 'personas' => []], $path);
        $content = $this->readReport('c.md');
        $this->assertStringNotContainsString('Persona Distribution', $content);
    }

    public function test_corpus_summary_no_scam_type_section_when_empty(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 0, 'approved' => 0, 'fallback' => 0, 'total_cost' => 0, 'scam_types' => []], $path);
        $content = $this->readReport('c.md');
        $this->assertStringNotContainsString('Scam Type Distribution', $content);
    }

    public function test_corpus_summary_no_language_section_when_empty(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 0, 'approved' => 0, 'fallback' => 0, 'total_cost' => 0, 'languages' => []], $path);
        $content = $this->readReport('c.md');
        $this->assertStringNotContainsString('Language Distribution', $content);
    }

    // === File creation ===

    public function test_quality_report_creates_file(): void
    {
        $path = $this->tmpDir . '/test.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $this->assertFileExists($path);
    }

    public function test_bandit_report_creates_file(): void
    {
        $path = $this->tmpDir . '/bandit.md';
        $this->writer->writeBanditReport(['total_conversations' => 0, 'overall_convergence' => false, 'cumulative_regret' => 0, 'random_baseline_regret' => 0], $path);
        $this->assertFileExists($path);
    }

    public function test_corpus_summary_creates_file(): void
    {
        $path = $this->tmpDir . '/corpus.md';
        $this->writer->writeCorpusSummary(['total' => 0, 'approved' => 0, 'fallback' => 0, 'total_cost' => 0], $path);
        $this->assertFileExists($path);
    }

    // === Directory creation ===

    public function test_creates_directory_if_not_exists(): void
    {
        $path = $this->tmpDir . '/subdir/report.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $this->assertFileExists($path);
    }

    // === Empty data produces minimal valid report ===

    public function test_empty_quality_report_still_has_heading(): void
    {
        $path = $this->tmpDir . '/empty.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $content = $this->readReport('empty.md');
        $this->assertStringContainsString('# Reply Quality Evaluation Report', $content);
        $this->assertStringContainsString('**Generated**:', $content);
        $this->assertStringContainsString('0 entries', $content);
    }

    // === Reply text truncated to 200 chars ===

    public function test_long_reply_text_truncated_in_blockquote(): void
    {
        $path = $this->tmpDir . '/q.md';
        $longText = str_repeat('x', 300);
        $best = [['persona_code' => 'p', 'scam_type' => 's', 'text' => $longText, 'naturalness' => 5, 'persona_fit' => 5, 'ti_value' => 5, 'word_count' => 100]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        // The text should be truncated to 200 chars via mb_substr
        $this->assertStringContainsString('> ' . str_repeat('x', 200), $content);
        $this->assertStringNotContainsString(str_repeat('x', 300), $content);
    }

    // === Metric value formatted to 2 decimal places ===

    public function test_metric_value_formatted_2_decimals(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.12345, 0.6, 'gt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('0.12', $content);
    }

    public function test_metric_threshold_formatted_2_decimals(): void
    {
        $path = $this->tmpDir . '/q.md';
        $metrics = [new MetricResult('m', 'd', 0.5, 0.66789, 'gt', 50, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 50, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('0.67', $content);
    }

    // === INSUFFICIENT_DATA verdict rendered with underscores ===

    public function test_insufficient_data_verdict_italic(): void
    {
        $path = $this->tmpDir . '/q.md';
        // sampleSize=5 < minSampleSize=10 => verdict = INSUFFICIENT_DATA
        $metrics = [new MetricResult('m', 'd', 0.9, 0.6, 'gt', 5, 'detail')];
        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 5, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('_INSUFFICIENT_DATA_', $content);
    }

    // === Best/worst reply numbering starts at 1 ===

    public function test_best_reply_numbered_from_1(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [
            ['persona_code' => 'p1', 'scam_type' => 's1', 'text' => 'first', 'naturalness' => 5, 'persona_fit' => 4, 'ti_value' => 3, 'word_count' => 50],
            ['persona_code' => 'p2', 'scam_type' => 's2', 'text' => 'second', 'naturalness' => 4, 'persona_fit' => 3, 'ti_value' => 2, 'word_count' => 40],
        ];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('#1', $content);
        $this->assertStringContainsString('#2', $content);
    }

    // === Worst reply also numbered ===

    public function test_worst_reply_numbered_from_1(): void
    {
        $path = $this->tmpDir . '/q.md';
        $worst = [
            ['persona_code' => 'p1', 'scam_type' => 's1', 'text' => 'bad1', 'naturalness' => 1, 'persona_fit' => 1, 'ti_value' => 1, 'word_count' => 5],
        ];
        $this->writer->writeQualityReport([], [], $worst, [], 'FAIL', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('#1', $content);
    }

    // === Corpus size exact text format ===

    public function test_corpus_size_format_exact(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 123, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**Corpus size**: 123 entries', $content);
    }

    // === Overall verdict format exact ===

    public function test_overall_verdict_format_exact(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'WARN', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('**Overall verdict**: **WARN**', $content);
    }

    // === Bandit report: scam type table has correct columns ===

    public function test_bandit_scam_type_table_headers(): void
    {
        $path = $this->tmpDir . '/b.md';
        $report = [
            'total_conversations' => 10,
            'overall_convergence' => false,
            'cumulative_regret' => 0,
            'random_baseline_regret' => 0,
            'scam_type_analyses' => [
                ['scam_type' => 'X', 'sessions_count' => 5, 'dominant_persona' => 'p', 'dominant_percentage' => 0.5, 'converged' => false],
            ],
        ];
        $this->writer->writeBanditReport($report, $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('| Scam Type | Sessions | Dominant Persona | Share | Converged |', $content);
    }

    // === Bandit not-converged shows lowercase 'no' ===

    public function test_bandit_not_converged_lowercase_no(): void
    {
        $path = $this->tmpDir . '/b.md';
        $report = [
            'total_conversations' => 10,
            'overall_convergence' => false,
            'cumulative_regret' => 0,
            'random_baseline_regret' => 0,
            'scam_type_analyses' => [
                ['scam_type' => 'X', 'sessions_count' => 5, 'dominant_persona' => 'p', 'dominant_percentage' => 0.5, 'converged' => false],
            ],
        ];
        $this->writer->writeBanditReport($report, $path);
        $content = $this->readReport('b.md');
        // Converged false should show 'no' (lowercase)
        $this->assertMatchesRegularExpression('/\| no \|/', $content);
    }

    // === Bandit report: sessions count shown as integer ===

    public function test_bandit_sessions_count_exact(): void
    {
        $path = $this->tmpDir . '/b.md';
        $report = [
            'total_conversations' => 10,
            'overall_convergence' => false,
            'cumulative_regret' => 0,
            'random_baseline_regret' => 0,
            'scam_type_analyses' => [
                ['scam_type' => 'PHISHING', 'sessions_count' => 42, 'dominant_persona' => 'elderly', 'dominant_percentage' => 0.6, 'converged' => false],
            ],
        ];
        $this->writer->writeBanditReport($report, $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('| 42 |', $content);
    }

    // === Bandit regret analysis section present ===

    public function test_bandit_regret_reduction_exact_format(): void
    {
        $path = $this->tmpDir . '/b.md';
        // regretReduction = (1 - 2.0/10.0) * 100 = 80.0%
        $this->writer->writeBanditReport([
            'total_conversations' => 10,
            'overall_convergence' => false,
            'cumulative_regret' => 2.0,
            'random_baseline_regret' => 10.0,
        ], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('Regret reduction vs random: 80.0%', $content);
    }

    // === Corpus summary: $-formatted cost ===

    public function test_corpus_cost_with_dollar_sign(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 2.3456], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('$2.3456', $content);
    }

    // === Corpus summary: exact label text ===

    public function test_corpus_total_entries_label(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 77, 'approved' => 70, 'fallback' => 7, 'total_cost' => 0], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('**Total entries**: 77', $content);
        $this->assertStringContainsString('**Approved**: 70', $content);
        $this->assertStringContainsString('**Fallback used**: 7', $content);
    }

    // === Corpus summary: table formatting ===

    public function test_corpus_persona_table_format(): void
    {
        $path = $this->tmpDir . '/c.md';
        $this->writer->writeCorpusSummary(['total' => 10, 'approved' => 10, 'fallback' => 0, 'total_cost' => 0, 'personas' => ['elderly' => 3]], $path);
        $content = $this->readReport('c.md');
        $this->assertStringContainsString('| elderly | 3 |', $content);
    }

    // === Persona matrix table header includes persona names ===

    public function test_persona_matrix_header_has_names(): void
    {
        $path = $this->tmpDir . '/q.md';
        $matrix = ['alpha' => ['alpha' => 1.0, 'beta' => 0.5], 'beta' => ['alpha' => 0.5, 'beta' => 1.0]];
        $this->writer->writeQualityReport([], [], [], $matrix, 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('alpha', $content);
        $this->assertStringContainsString('beta', $content);
    }

    // === Reply text blockquote with newline handling ===

    public function test_multiline_reply_text_blockquoted(): void
    {
        $path = $this->tmpDir . '/q.md';
        $text = "First line\nSecond line";
        $best = [['persona_code' => 'p', 'scam_type' => 's', 'text' => $text, 'naturalness' => 5, 'persona_fit' => 5, 'ti_value' => 5, 'word_count' => 10]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString("> First line\n> Second line", $content);
    }

    // === Bandit total conversations label ===

    public function test_bandit_total_conversations_label(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 99, 'overall_convergence' => false, 'cumulative_regret' => 0, 'random_baseline_regret' => 0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('**Total conversations analyzed**: 99', $content);
    }

    // === Bandit cumulative regret label ===

    public function test_bandit_cumulative_regret_label(): void
    {
        $path = $this->tmpDir . '/b.md';
        $this->writer->writeBanditReport(['total_conversations' => 10, 'overall_convergence' => false, 'cumulative_regret' => 3.14, 'random_baseline_regret' => 7.0], $path);
        $content = $this->readReport('b.md');
        $this->assertStringContainsString('Cumulative regret (vs oracle): 3.14', $content);
        $this->assertStringContainsString('Random baseline regret: 7.00', $content);
    }

    // === File ends with newline ===

    public function test_file_ends_with_newline(): void
    {
        $path = $this->tmpDir . '/q.md';
        $this->writer->writeQualityReport([], [], [], [], 'PASS', 0, $path);
        $content = file_get_contents($path);
        $this->assertStringEndsWith("\n", $content);
    }

    // === Missing entry fields default to ? ===

    public function test_missing_persona_code_defaults_to_question_mark(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [['scam_type' => 'PHISHING', 'text' => 'text', 'naturalness' => 5, 'persona_fit' => 5, 'ti_value' => 5, 'word_count' => 10]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('? / PHISHING', $content);
    }

    public function test_missing_scam_type_defaults_to_question_mark(): void
    {
        $path = $this->tmpDir . '/q.md';
        $best = [['persona_code' => 'elderly', 'text' => 'text', 'naturalness' => 5, 'persona_fit' => 5, 'ti_value' => 5, 'word_count' => 10]];
        $this->writer->writeQualityReport([], $best, [], [], 'PASS', 10, $path);
        $content = $this->readReport('q.md');
        $this->assertStringContainsString('elderly / ?', $content);
    }
}
