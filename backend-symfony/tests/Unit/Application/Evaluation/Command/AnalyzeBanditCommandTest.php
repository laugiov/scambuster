<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Command;

use App\Application\Evaluation\BanditAnalyzer;
use App\Application\Evaluation\Report\JsonReportWriter;
use App\Application\Evaluation\Report\MarkdownReportWriter;
use App\UI\Console\AnalyzeBanditCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyzeBanditCommandTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eval_bandit_cmd_' . uniqid();
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

    public function test_command_produces_output(): void
    {
        $rows = [];

        for ($i = 0; $i < 10; ++$i) {
            $rows[] = [
                'conv_id' => 'c-' . $i,
                'scam_type' => 'PHISHING',
                'persona_code' => $i < 8 ? 'elderly' : 'tech',
                'reward_value' => 0.7,
                'status' => 'closed',
                'engagement_duration_sec' => 3600,
                'created_at' => '2026-03-01 00:00:00',
            ];
        }

        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $command = new AnalyzeBanditCommand(
            $analyzer,
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--output-dir' => $this->tmpDir]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('PHISHING', $output);
        $this->assertStringContainsString('elderly', $output);
    }

    public function test_command_with_empty_data(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAllAssociative')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $analyzer = new BanditAnalyzer($em);
        $command = new AnalyzeBanditCommand(
            $analyzer,
            new JsonReportWriter(),
            new MarkdownReportWriter(),
        );

        $tester = new CommandTester($command);
        $tester->execute(['--output-dir' => $this->tmpDir]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('0', $tester->getDisplay());
    }
}
