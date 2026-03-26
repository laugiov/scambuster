<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SiemTestCommandTest extends KernelTestCase
{
    public function testCommandExecutes(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:siem:test'));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('SIEM Connector Test', $output);
        $this->assertStringContainsString('Provider:', $output);
    }

    public function testCommandReportsProviderName(): void
    {
        self::bootKernel();
        $app = new Application(self::$kernel);
        $tester = new CommandTester($app->find('app:siem:test'));

        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Provider:\s+(none|file|syslog)/', $output);
    }
}
