<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Command;

use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use App\Application\Evaluation\ReplyQualityAnalyzer;
use App\UI\Console\EvaluateReplyQualityCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EvaluateReplyQualityCommandTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eval_cmd_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/**/*') ?: [];
        $files = array_merge($files, glob($this->tmpDir . '/*') ?: []);

        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        $dirs = glob($this->tmpDir . '/*') ?: [];

        foreach ($dirs as $d) {
            if (is_dir($d)) {
                rmdir($d);
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_command_analyzes_corpus_file(): void
    {
        $corpus = $this->buildCorpusFile();
        $outputDir = $this->tmpDir . '/output';

        $command = new EvaluateReplyQualityCommand(
            new ReplyQualityAnalyzer(),
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'corpus-file' => $corpus,
            '--output-dir' => $outputDir,
        ]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('non_repetitiveness', $output);
        $this->assertStringContainsString('opening_diversity', $output);
        $this->assertStringContainsString('security_pass_rate', $output);
    }

    public function test_command_fails_on_missing_file(): void
    {
        $command = new EvaluateReplyQualityCommand(
            new ReplyQualityAnalyzer(),
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['corpus-file' => '/nonexistent/file.json']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_command_fails_on_empty_corpus(): void
    {
        $path = $this->tmpDir . '/empty.json';
        file_put_contents($path, json_encode(['entries' => []]));

        $command = new EvaluateReplyQualityCommand(
            new ReplyQualityAnalyzer(),
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['corpus-file' => $path]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    private function buildCorpusFile(): string
    {
        $entries = [];
        $personas = ['elderly', 'accountant', 'romantic'];
        $openings = [
            'Oh dear, I received your message about the bank transfer details.',
            'I need the SIRET number and documentation before any payment.',
            'Your message touched my heart deeply since my husband passed.',
            'Could you please explain the process in simpler terms for me?',
            'I have consulted my accountant about the financial aspects.',
        ];

        for ($i = 0; $i < 20; ++$i) {
            $entries[] = [
                'conv_id' => 'c-' . ($i % 5),
                'scam_type' => $i % 2 === 0 ? 'PHISHING' : 'ROMANCE',
                'persona_code' => $personas[$i % 3],
                'message_count' => 3,
                'detected_language' => 'fr',
                'reply_language' => 'fr',
                'text' => $openings[$i % 5] . ' Additional unique content for entry ' . $i . '.',
                'word_count' => 60,
                'attempts' => $i % 3 === 0 ? 2 : 1,
                'fallback_used' => false,
                'approved' => true,
                'naturalness' => rand(3, 5),
                'persona_fit' => rand(3, 5),
                'ti_value' => rand(2, 5),
                'security_pass' => true,
                'policy_flags' => [],
                'cost_estimate' => 0.003,
            ];
        }

        $path = $this->tmpDir . '/corpus.json';
        file_put_contents($path, json_encode(['entries' => $entries]));

        return $path;
    }
}
