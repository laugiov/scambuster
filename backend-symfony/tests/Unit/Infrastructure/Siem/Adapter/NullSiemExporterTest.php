<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Adapter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use PHPUnit\Framework\TestCase;

class NullSiemExporterTest extends TestCase
{
    private NullSiemExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new NullSiemExporter();
    }

    public function testGetProviderName(): void
    {
        $this->assertSame('none', $this->exporter->getProviderName());
    }

    public function testIsHealthyAlwaysReturnsTrue(): void
    {
        $this->assertTrue($this->exporter->isHealthy());
    }

    public function testExportDoesNotThrow(): void
    {
        $event = $this->createEvent();
        $this->exporter->export($event);
        $this->addToAssertionCount(1);
    }

    public function testExportBatchDoesNotThrow(): void
    {
        $events = [$this->createEvent(), $this->createEvent()];
        $this->exporter->exportBatch($events);
        $this->addToAssertionCount(1);
    }

    public function testExportBatchEmptyDoesNotThrow(): void
    {
        $this->exporter->exportBatch([]);
        $this->addToAssertionCount(1);
    }

    private function createEvent(): SiemEvent
    {
        return new SiemEvent(
            timestamp: new \DateTimeImmutable(),
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
