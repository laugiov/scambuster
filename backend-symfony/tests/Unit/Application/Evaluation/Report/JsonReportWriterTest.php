<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Report;

use App\Application\Evaluation\Report\JsonReportWriter;
use PHPUnit\Framework\TestCase;

final class JsonReportWriterTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/eval_test_' . uniqid();
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

    public function test_write_creates_json_file(): void
    {
        $writer = new JsonReportWriter();
        $path = $this->tmpDir . '/report.json';

        $writer->write(['test' => 'value', 'count' => 42], $path);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true);
        $this->assertSame('value', $decoded['test']);
        $this->assertSame(42, $decoded['count']);
    }

    public function test_write_creates_directory_if_missing(): void
    {
        $writer = new JsonReportWriter();
        $path = $this->tmpDir . '/report.json';

        $this->assertDirectoryDoesNotExist($this->tmpDir);

        $writer->write(['data' => true], $path);

        $this->assertDirectoryExists($this->tmpDir);
        $this->assertFileExists($path);
    }

    public function test_write_pretty_prints_json(): void
    {
        $writer = new JsonReportWriter();
        $path = $this->tmpDir . '/report.json';

        $writer->write(['a' => 1], $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString("\n", $content);
    }

    public function test_write_handles_unicode(): void
    {
        $writer = new JsonReportWriter();
        $path = $this->tmpDir . '/report.json';

        $writer->write(['text' => 'Bonjour, je suis intéressée'], $path);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('intéressée', $content);
    }
}
