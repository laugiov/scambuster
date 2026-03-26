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

    public function testCommandExecutesSuccessfully(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Dry run', $output);
    }

    public function testSinceOptionWithAbsoluteDate(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '2026-01-01']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('2026-01-01', $output);
    }

    public function testOutputContainsProviderLine(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Provider:', $output);
    }

    public function testOutputContainsEventsFoundLine(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Events found:', $output);
    }

    public function testBatchSizeOption(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h', '--batch-size' => '50']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Batch size: 50', $output);
    }

    public function testOutputContainsBatchExportTitle(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('SIEM Batch Export', $output);
    }

    public function testDryRunDisplaysYes(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '24h']);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Dry run: yes', $output);
    }

    public function testSinceOptionWithRelativeMinutes(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '30m']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Events found:', $output);
    }

    public function testSinceOptionWithRelativeDays(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => '7d']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Since:', $output);
    }

    public function testSinceOptionWithInvalidValueFallsBackToDefault(): void
    {
        $this->tester->execute(['--dry-run' => true, '--since' => 'invalid-date-format']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        // Falls back to -24 hours
        $this->assertStringContainsString('Since:', $output);
    }
}
