<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SiemTestCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $this->tester = new CommandTester($app->find('app:siem:test'));
    }

    public function testCommandExecutes(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('SIEM Connector Test', $output);
        $this->assertStringContainsString('Provider:', $output);
    }

    public function testCommandReportsProviderName(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertMatchesRegularExpression('/Provider:\s+(none|file|syslog)/', $output);
    }

    public function testOutputContainsHealthCheckLine(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        // When provider is 'none', the command warns and exits before health check
        // When provider is active, it reports health check status
        $this->assertTrue(
            str_contains($output, 'Health check:') || str_contains($output, 'SIEM export is disabled'),
            'Output should contain either Health check result or disabled warning'
        );
    }

    public function testExitCodeIsZero(): void
    {
        $this->tester->execute([]);

        // NullSiemExporter returns 'none', so the command returns SUCCESS with a warning
        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testNoneProviderShowsDisabledWarning(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        // In test environment, SIEM_PROVIDER is typically 'none'
        if (str_contains($output, 'Provider: none')) {
            $this->assertStringContainsString('SIEM export is disabled', $output);
        } else {
            // If a real provider is configured, expect health check
            $this->assertStringContainsString('Health check:', $output);
        }
    }
}
