<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Audit\AuditEventQueryService;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\UI\Console\SiemExportCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SiemExportCommandTest extends TestCase
{
    private function makeSiemEvent(string $eventType = 'AUTH_SUCCESS'): SiemEvent
    {
        return new SiemEvent(
            timestamp: new \DateTimeImmutable('2026-03-20 10:00:00'),
            eventType: AuditEventType::from($eventType),
            severity: 5,
            actorType: 'user',
            actorId: 'test@example.com',
            action: 'login',
            outcome: 'success',
            details: ['test' => true],
            resourceType: null,
            resourceId: null,
            ipAddress: '127.0.0.1',
            traceId: 'trace-123',
        );
    }

    private function makeQueryService(array $events = []): AuditEventQueryService
    {
        $service = $this->createMock(AuditEventQueryService::class);
        $service->method('fetchEventsSince')->willReturn($events);
        $service->method('parseSince')->willReturnCallback(function (string $value): \DateTimeImmutable {
            if (preg_match('/^(\d+)([hdm])$/', $value, $m)) {
                $amount = (int) $m[1];
                $unit = match ($m[2]) {
                    'h' => 'hours',
                    'd' => 'days',
                    'm' => 'minutes',
                };

                return new \DateTimeImmutable("-{$amount} {$unit}");
            }
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($date !== false) {
                return $date->setTime(0, 0);
            }

            return new \DateTimeImmutable('-24 hours');
        });

        return $service;
    }

    private function createCommand(
        SiemExporterInterface $exporter,
        array $events = [],
    ): CommandTester {
        $command = new SiemExportCommand($exporter, $this->makeQueryService($events));
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:siem:export'));
    }

    private function makeExporter(string $providerName = 'file'): SiemExporterInterface
    {
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn($providerName);
        $exporter->method('isHealthy')->willReturn(true);

        return $exporter;
    }

    public function testNoneProviderReturnsFailure(): void
    {
        $tester = $this->createCommand($this->makeExporter('none'));

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('SIEM export is disabled', $output);
    }

    public function testOutputShowsProviderFile(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Provider: file', $output);
    }

    public function testOutputShowsSinceFormattedDate(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Since: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $output);
    }

    public function testSince7dParsesCorrectly(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--since' => '7d']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Since:', $output);
        $expected = (new \DateTimeImmutable('-7 days'))->format('Y-m-d');
        $this->assertStringContainsString($expected, $output);
    }

    public function testBatchSizeShownInOutput(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--batch-size' => '50']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Batch size: 50', $output);
    }

    public function testDryRunNoDisplaysNo(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--since' => '24h']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry run: no', $output);
    }

    public function testNoEventsReportsSuccess(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'), []);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Events found: 0', $output);
        $this->assertStringContainsString('No events to export', $output);
    }

    public function testDryRunWithEventsShowsCount(): void
    {
        $events = [$this->makeSiemEvent(), $this->makeSiemEvent('AUTH_FAILURE')];
        $tester = $this->createCommand($this->makeExporter('file'), $events);

        $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Events found: 2', $output);
        $this->assertStringContainsString('Dry run: 2 events would be exported', $output);
    }

    public function testExportEventsCallsExporterBatch(): void
    {
        $events = [$this->makeSiemEvent(), $this->makeSiemEvent('MESSAGE_INGESTED')];
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn('file');
        $exporter->expects($this->once())->method('exportBatch');

        $command = new SiemExportCommand($exporter, $this->makeQueryService($events));
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('app:siem:export'));

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Exported 2 events to file provider', $output);
    }

    public function testExportWithMultipleBatches(): void
    {
        $events = [$this->makeSiemEvent(), $this->makeSiemEvent(), $this->makeSiemEvent()];
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn('file');
        $exporter->expects($this->exactly(2))->method('exportBatch');

        $command = new SiemExportCommand($exporter, $this->makeQueryService($events));
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('app:siem:export'));

        $tester->execute(['--batch-size' => '2']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Exported 3 events to file provider', $output);
        $this->assertStringContainsString('Exported 2 / 3', $output);
    }

    public function testSinceWithAbsoluteDateParsesCorrectly(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--since' => '2026-01-15']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Since: 2026-01-15 00:00:00', $output);
    }

    public function testSinceWithMinutesParsesCorrectly(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--since' => '30m']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Since:', $output);
    }

    public function testSinceWithInvalidFallsBackToDefault(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute(['--dry-run' => true, '--since' => 'invalid']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Since:', $output);
    }

    public function testSyslogProvider(): void
    {
        $tester = $this->createCommand($this->makeExporter('syslog'));

        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Provider: syslog', $output);
    }
}
