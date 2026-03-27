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
    }

    public function testCommandReportsProvider(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Provider:', $output);
    }

    public function testCommandExitCodeIsZero(): void
    {
        $this->tester->execute([]);

        // Both provider=none (warning) and provider=file (success) return 0
        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testCommandOutputHandlesBothProviders(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();

        // Provider=none shows warning, provider=file/syslog shows health check
        $this->assertTrue(
            str_contains($output, 'SIEM export is disabled') || str_contains($output, 'Health check:'),
            'Output should contain either disabled warning or health check result'
        );
    }
}
