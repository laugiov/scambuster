<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SiemExportCommandTest extends KernelTestCase
{
    public function testCommandExecutesSuccessfully(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:siem:export'));

        $tester->execute(['--dry-run' => true, '--since' => '24h']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry run', $output);
    }

    public function testSinceOptionWithAbsoluteDate(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:siem:export'));

        $tester->execute(['--dry-run' => true, '--since' => '2026-01-01']);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('2026-01-01', $output);
    }
}
