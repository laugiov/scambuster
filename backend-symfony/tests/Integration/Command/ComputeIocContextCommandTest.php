<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ComputeIocContextCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ComputeIocContextCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $command = self::getContainer()->get(ComputeIocContextCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $this->tester = new CommandTester($command);
    }

    public function testDryRunSucceeds(): void
    {
        $this->tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        $this->assertStringContainsString('Dry-run mode', $this->tester->getDisplay());
    }

    public function testEmptyDatabaseReturnsSuccess(): void
    {
        $this->tester->execute(['--limit' => '1']);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }

    public function testWithLlmFlagWithoutEnricherSucceeds(): void
    {
        $this->tester->execute(['--with-llm' => true, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $this->tester->getStatusCode());
    }
}
