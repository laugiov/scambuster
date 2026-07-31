<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Report;

use App\Application\Evaluation\Metric\MetricResult;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use PHPUnit\Framework\TestCase;

final class MarkdownReportWriterTest extends TestCase
{
    private string $tmpDir = '';
    private MarkdownReportWriter $writer; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eval_md_' . uniqid();
        $this->writer = new MarkdownReportWriter();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');

        if ($files !== false) {
            array_map('unlink', $files);
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_write_quality_report_creates_file(): void
    {
        $metrics = [
            new MetricResult('test_metric', 'diversity', 0.25, 0.30, 'lt', 50, 'Test detail'),
        ];
        $path = $this->tmpDir . '/quality.md';

        $this->writer->writeQualityReport($metrics, [], [], [], 'PASS', 100, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('# Reply Quality Evaluation Report', $content);
        $this->assertStringContainsString('PASS', $content);
        $this->assertStringContainsString('test_metric', $content);
        $this->assertStringContainsString('100 entries', $content);
    }

    public function test_write_quality_report_with_best_worst(): void
    {
        $metrics = [
            new MetricResult('m1', 'dim', 0.5, 0.6, 'gt', 20, 'detail'),
        ];
        $best = [
            ['persona_code' => 'elderly', 'scam_type' => 'PHISHING', 'text' => 'Oh dear me, I received this.', 'naturalness' => 5, 'persona_fit' => 4, 'ti_value' => 3, 'word_count' => 50],
        ];
        $worst = [
            ['persona_code' => 'tech_newbie', 'scam_type' => 'ROMANCE', 'text' => 'Bad reply here.', 'naturalness' => 1, 'persona_fit' => 1, 'ti_value' => 1, 'word_count' => 10],
        ];
        $path = $this->tmpDir . '/quality.md';

        $this->writer->writeQualityReport($metrics, $best, $worst, [], 'FAIL', 50, $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('Top 5 Best', $content);
        $this->assertStringContainsString('Bottom 5 Worst', $content);
        $this->assertStringContainsString('elderly', $content);
    }

    public function test_write_quality_report_with_persona_matrix(): void
    {
        $metrics = [
            new MetricResult('m1', 'dim', 0.5, 0.6, 'gt', 20, 'd'),
        ];
        $matrix = [
            'elderly' => ['elderly' => 1.0, 'tech' => 0.3],
            'tech' => ['elderly' => 0.3, 'tech' => 1.0],
        ];
        $path = $this->tmpDir . '/quality.md';

        $this->writer->writeQualityReport($metrics, [], [], $matrix, 'PASS', 100, $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('Persona Similarity Matrix', $content);
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('1.00', $content);
    }

    public function test_write_bandit_report(): void
    {
        $report = [
            'total_conversations' => 40,
            'overall_convergence' => false,
            'scam_type_analyses' => [
                [
                    'scam_type' => 'PHISHING',
                    'sessions_count' => 15,
                    'dominant_persona' => 'elderly',
                    'dominant_percentage' => 0.80,
                    'converged' => true,
                ],
            ],
            'cumulative_regret' => 1.5,
            'random_baseline_regret' => 5.0,
        ];
        $path = $this->tmpDir . '/bandit.md';

        $this->writer->writeBanditReport($report, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('Bandit Convergence', $content);
        $this->assertStringContainsString('PHISHING', $content);
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('Regret', $content);
    }

    public function test_write_bandit_report_zero_baseline(): void
    {
        $report = [
            'total_conversations' => 0,
            'overall_convergence' => false,
            'scam_type_analyses' => [],
            'cumulative_regret' => 0,
            'random_baseline_regret' => 0,
        ];
        $path = $this->tmpDir . '/bandit.md';

        $this->writer->writeBanditReport($report, $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('0.0%', $content);
    }

    public function test_write_corpus_summary(): void
    {
        $summary = [
            'total' => 500,
            'approved' => 480,
            'fallback' => 20,
            'total_cost' => 1.5,
            'personas' => ['elderly' => 200, 'tech' => 300],
            'scam_types' => ['PHISHING' => 250, 'ROMANCE' => 250],
            'languages' => ['fr' => 400, 'en' => 100],
        ];
        $path = $this->tmpDir . '/summary.md';

        $this->writer->writeCorpusSummary($summary, $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('Corpus Generation Summary', $content);
        $this->assertStringContainsString('500', $content);
        $this->assertStringContainsString('$1.5000', $content);
        $this->assertStringContainsString('elderly', $content);
        $this->assertStringContainsString('PHISHING', $content);
    }

    public function test_write_corpus_summary_empty(): void
    {
        $summary = [
            'total' => 0,
            'approved' => 0,
            'fallback' => 0,
            'total_cost' => 0,
            'personas' => [],
            'scam_types' => [],
            'languages' => [],
        ];
        $path = $this->tmpDir . '/summary.md';

        $this->writer->writeCorpusSummary($summary, $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringNotContainsString('Persona Distribution', $content);
    }
}
