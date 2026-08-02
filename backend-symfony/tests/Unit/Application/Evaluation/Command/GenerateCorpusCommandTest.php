<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Command;

use App\Application\Evaluation\CorpusGenerator;
use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use App\UI\Console\GenerateCorpusCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateCorpusCommandTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eval_gen_cmd_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        array_map('unlink', array_filter($files, 'is_file'));

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function test_command_dry_run(): void
    {
        $generator = $this->createMock(CorpusGenerator::class);
        $generator->method('generate')->willReturn([
            'entries' => [
                ['conv_id' => 'c1', 'text' => '[DRY RUN]', 'persona_code' => 'elderly', 'scam_type' => 'PHISHING'],
            ],
            'summary' => [
                'total' => 1,
                'approved' => 0,
                'fallback' => 0,
                'total_cost' => 0.003,
                'dry_run' => true,
                'personas' => ['elderly' => 1],
                'scam_types' => ['PHISHING' => 1],
                'languages' => ['en' => 1],
            ],
        ]);

        $command = new GenerateCorpusCommand(
            $generator,
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--count' => '1',
            '--dry-run' => true,
            '--output-dir' => $this->tmpDir,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Generated 1 entries', $output);
        $this->assertStringContainsString('$0.0030', $output);
    }

    public function test_command_with_filters(): void
    {
        $generator = $this->createMock(CorpusGenerator::class);
        $generator->expects($this->once())->method('generate')->willReturn([
            'entries' => [],
            'summary' => [
                'total' => 0,
                'approved' => 0,
                'fallback' => 0,
                'total_cost' => 0.0,
                'dry_run' => true,
                'personas' => [],
                'scam_types' => [],
                'languages' => [],
            ],
        ]);

        $command = new GenerateCorpusCommand(
            $generator,
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--count' => '5',
            '--scam-type' => 'PHISHING',
            '--persona' => 'elderly_person',
            '--language' => 'fr',
            '--dry-run' => true,
            '--output-dir' => $this->tmpDir,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
