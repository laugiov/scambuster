<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Adapter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Adapter\SyslogSiemExporter;
use App\Infrastructure\Siem\Formatter\CefFormatter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SyslogSiemExporterTest extends TestCase
{
    public function testGetProviderName(): void
    {
        $exporter = $this->createExporter('udp://127.0.0.1:514');
        $this->assertSame('syslog', $exporter->getProviderName());
    }

    public function testIsHealthyReturnsFalseForInvalidEndpoint(): void
    {
        $exporter = $this->createExporter('invalid-endpoint');
        $this->assertFalse($exporter->isHealthy());
    }

    public function testIsHealthyReturnsFalseForUnreachableHost(): void
    {
        // Use a non-routable IP to guarantee connection failure
        $exporter = $this->createExporter('tcp://192.0.2.1:59999');
        $this->assertFalse($exporter->isHealthy());
    }

    public function testExportDoesNotThrowOnConnectionFailure(): void
    {
        $exporter = $this->createExporter('tcp://192.0.2.1:59999');

        // Non-blocking: must not throw
        $exporter->export($this->createEvent());
        $this->addToAssertionCount(1);
    }

    public function testExportDoesNotThrowOnInvalidEndpoint(): void
    {
        $exporter = $this->createExporter('not-a-valid-endpoint');
        $exporter->export($this->createEvent());
        $this->addToAssertionCount(1);
    }

    public function testExportBatchDoesNotThrowOnFailure(): void
    {
        $exporter = $this->createExporter('tcp://192.0.2.1:59999');
        $exporter->exportBatch([$this->createEvent(), $this->createEvent()]);
        $this->addToAssertionCount(1);
    }

    private function createExporter(string $endpoint): SyslogSiemExporter
    {
        return new SyslogSiemExporter(
            new CefFormatter(),
            new NullLogger(),
            $endpoint,
        );
    }

    private function createEvent(): SiemEvent
    {
        return new SiemEvent(
            timestamp: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            eventType: AuditEventType::AUTH_SUCCESS,
            severity: 1,
            actorType: 'system',
            actorId: 'test',
            action: 'test',
            outcome: 'success',
            details: [],
        );
    }
}
