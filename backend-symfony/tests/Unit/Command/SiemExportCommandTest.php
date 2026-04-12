<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Audit\Port\SiemExporterInterface;
use App\UI\Console\SiemExportCommand;
use App\Domain\Audit\AuditEventType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SiemExportCommandTest extends TestCase
{
    private function createCommand(
        SiemExporterInterface $exporter,
        array $rows = [],
    ): CommandTester {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $command = new SiemExportCommand($exporter, $em);
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

    private function makeAuditRow(string $eventType = 'AUTH_SUCCESS'): array
    {
        return [
            'event_type' => $eventType,
            'created_at' => '2026-03-20 10:00:00',
            'actor_type' => 'user',
            'actor_id' => 'test@example.com',
            'action' => 'login',
            'outcome' => 'success',
            'details' => '{"test": true}',
            'resource_type' => null,
            'resource_id' => null,
            'ip_address' => '127.0.0.1',
            'trace_id' => 'trace-123',
        ];
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
        // 7 days ago should be a date in the past week
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
        $rows = [$this->makeAuditRow(), $this->makeAuditRow('AUTH_FAILURE')];
        $tester = $this->createCommand($this->makeExporter('file'), $rows);

        $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Events found: 2', $output);
        $this->assertStringContainsString('Dry run: 2 events would be exported', $output);
    }

    public function testExportEventsCallsExporterBatch(): void
    {
        $rows = [$this->makeAuditRow(), $this->makeAuditRow('MESSAGE_INGESTED')];
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn('file');
        $exporter->expects($this->once())->method('exportBatch');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $command = new SiemExportCommand($exporter, $em);
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
        // 3 events with batch-size=2 should produce 2 exportBatch calls
        $rows = [$this->makeAuditRow(), $this->makeAuditRow(), $this->makeAuditRow()];
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn('file');
        $exporter->expects($this->exactly(2))->method('exportBatch');

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $command = new SiemExportCommand($exporter, $em);
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
