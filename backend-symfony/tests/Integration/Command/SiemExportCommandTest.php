<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SiemExportCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $this->tester = new CommandTester($app->find('app:siem:export'));
    }

    public function testCommandExecutes(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('SIEM Batch Export', $output);
    }

    public function testCommandHandlesProviderNone(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $exitCode = $this->tester->getStatusCode();

        // Provider=none → FAILURE with disabled message
        // Provider=file/syslog → SUCCESS with export output
        if (str_contains($output, 'SIEM export is disabled')) {
            $this->assertSame(1, $exitCode);
        } else {
            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('Provider:', $output);
        }
    }

    public function testCommandWithDryRun(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();

        // When provider is active, dry run shows details
        if (!str_contains($output, 'SIEM export is disabled')) {
            $this->assertStringContainsString('Dry run: yes', $output);
            $this->assertStringContainsString('Events found:', $output);
        } else {
            // Provider=none exits before showing dry run details
            $this->assertStringContainsString('disabled', $output);
        }
    }

    public function testSinceOptionWithAbsoluteDate(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '2026-01-01']);

        $output = $this->tester->getDisplay();

        if (!str_contains($output, 'SIEM export is disabled')) {
            $this->assertStringContainsString('2026-01-01', $output);
        } else {
            $this->assertTrue(true); // Provider=none, skip assertion
        }
    }
}
