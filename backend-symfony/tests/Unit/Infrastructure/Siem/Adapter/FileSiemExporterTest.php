<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Adapter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Adapter\FileSiemExporter;
use App\Infrastructure\Siem\Formatter\JsonFormatter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FileSiemExporterTest extends TestCase
{
    private string $tmpDir;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/siem-test-' . uniqid();
        $this->tmpFile = $this->tmpDir . '/events.ndjson';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testGetProviderName(): void
    {
        $exporter = $this->createExporter();
        $this->assertSame('file', $exporter->getProviderName());
    }

    public function testExportCreatesDirectoryAndFile(): void
    {
        $exporter = $this->createExporter();
        $exporter->export($this->createEvent());

        $this->assertFileExists($this->tmpFile);
    }

    public function testExportWritesValidJsonLine(): void
    {
        $exporter = $this->createExporter();
        $exporter->export($this->createEvent(AuditEventType::IOC_EXTRACTED));

        $content = file_get_contents($this->tmpFile);
        $this->assertNotFalse($content);

        $lines = array_filter(explode("\n", $content));
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertIsArray($decoded);
        $this->assertSame('IOC_EXTRACTED', $decoded['event_type']);
    }

    public function testExportAppendsToExistingFile(): void
    {
        $exporter = $this->createExporter();
        $exporter->export($this->createEvent(AuditEventType::AUTH_SUCCESS));
        $exporter->export($this->createEvent(AuditEventType::AUTH_FAILURE));

        $content = file_get_contents($this->tmpFile);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(2, $lines);
    }

    public function testExportBatchWritesAllEvents(): void
    {
        $exporter = $this->createExporter();
        $events = [
            $this->createEvent(AuditEventType::AUTH_SUCCESS),
            $this->createEvent(AuditEventType::IOC_EXTRACTED),
            $this->createEvent(AuditEventType::INJECTION_DETECTED),
        ];

        $exporter->exportBatch($events);

        $content = file_get_contents($this->tmpFile);
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(3, $lines);
    }

    public function testExportBatchEmptyWritesEmptyContent(): void
    {
        $exporter = $this->createExporter();
        $exporter->exportBatch([]);

        // exportBatch with empty array calls file_put_contents with empty string
        // The file may or may not be created depending on directory existence
        if (is_file($this->tmpFile)) {
            $this->assertSame('', trim(file_get_contents($this->tmpFile)));
        } else {
            $this->assertFileDoesNotExist($this->tmpFile);
        }
    }

    public function testIsHealthyReturnsTrueForWritableDir(): void
    {
        mkdir($this->tmpDir, 0o755, true);
        $exporter = $this->createExporter();

        $this->assertTrue($exporter->isHealthy());
    }

    public function testIsHealthyReturnsFalseForNonExistentDir(): void
    {
        $exporter = new FileSiemExporter(
            new JsonFormatter(),
            new NullLogger(),
            '/nonexistent/path/events.ndjson',
        );

        $this->assertFalse($exporter->isHealthy());
    }

    public function testExportHandlesWriteFailureGracefully(): void
    {
        $exporter = new FileSiemExporter(
            new JsonFormatter(),
            new NullLogger(),
            '/proc/0/nonexistent/events.ndjson',
        );

        // Should not throw
        $exporter->export($this->createEvent());
        $this->addToAssertionCount(1);
    }

    private function createExporter(): FileSiemExporter
    {
        return new FileSiemExporter(
            new JsonFormatter(),
            new NullLogger(),
            $this->tmpFile,
        );
    }

    private function createEvent(AuditEventType $type = AuditEventType::AUTH_SUCCESS): SiemEvent
    {
        return new SiemEvent(
            timestamp: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            eventType: $type,
            severity: 3,
            actorType: 'system',
            actorId: 'test',
            action: 'test',
            outcome: 'success',
            details: [],
        );
    }
}
