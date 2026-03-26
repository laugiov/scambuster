<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\PurgeRgpdCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeRgpdCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testPurgeWithRetentionSoftDeletesOldOutbound(): void
    {
        // Fixture conv 005 is CLOSED, ts_last = -3 years, deleted_at = null -> eligible for soft delete (> 2 years)
        // Fixture conv 006 is CLOSED, ts_last = -6 years, deleted_at = -5y-1d -> eligible for hard delete (> 5 years)
        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Soft-deleted outbound conversations:', $output);
        $this->assertStringContainsString('Hard-deleted inbound conversations:', $output);
    }

    public function testPurgeReportsZeroWhenNothingToDelete(): void
    {
        // Remove all conversations that would be eligible for purge
        // Set all conversations ts_last to recent date so none qualify
        $this->connection->executeStatement(
            "UPDATE conversation SET ts_last = NOW(), deleted_at = NULL"
        );

        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Soft-deleted outbound conversations: 0', $output);
        $this->assertStringContainsString('Hard-deleted inbound conversations: 0', $output);
    }

    public function testPurgeOutputContainsBothCountLines(): void
    {
        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        // Both lines should contain a numeric count
        $this->assertMatchesRegularExpression('/Soft-deleted outbound conversations: \d+/', $output);
        $this->assertMatchesRegularExpression('/Hard-deleted inbound conversations: \d+/', $output);
    }

    public function testPurgeReturnsSuccessExitCode(): void
    {
        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
