<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Audit\Port\SiemExporterInterface;
use App\UI\Console\SiemTestCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SiemTestCommandTest extends TestCase
{
    private function createCommand(SiemExporterInterface $exporter): CommandTester
    {
        $command = new SiemTestCommand($exporter);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:siem:test'));
    }

    private function makeExporter(string $provider = 'file', bool $healthy = true): SiemExporterInterface
    {
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn($provider);
        $exporter->method('isHealthy')->willReturn($healthy);

        return $exporter;
    }

    public function testOutputContainsProviderFile(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Provider: file', $output);
    }

    public function testOutputContainsHealthCheckOk(): void
    {
        $tester = $this->createCommand($this->makeExporter('file', true));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Health check: OK', $output);
    }

    public function testOutputContainsTestEventSentSuccessfully(): void
    {
        $exporter = $this->createMock(SiemExporterInterface::class);
        $exporter->method('getProviderName')->willReturn('file');
        $exporter->method('isHealthy')->willReturn(true);
        $exporter->expects($this->once())->method('export');

        $tester = $this->createCommand($exporter);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Test event sent successfully', $output);
    }

    public function testExitCodeIsZeroWhenProviderConfigured(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }

    public function testNoneProviderShowsWarning(): void
    {
        $tester = $this->createCommand($this->makeExporter('none'));

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Provider: none', $output);
        $this->assertStringContainsString('SIEM export is disabled', $output);
    }

    public function testUnhealthyProviderReturnsFailure(): void
    {
        $tester = $this->createCommand($this->makeExporter('file', false));

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Health check: FAILED', $output);
        $this->assertStringContainsString('SIEM target is not reachable', $output);
    }

    public function testSyslogProvider(): void
    {
        $tester = $this->createCommand($this->makeExporter('syslog'));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Provider: syslog', $output);
        $this->assertStringContainsString('Test event sent successfully to syslog provider', $output);
    }

    public function testConnectorTestTitle(): void
    {
        $tester = $this->createCommand($this->makeExporter('file'));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('SIEM Connector Test', $output);
    }
}
