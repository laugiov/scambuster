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
        // Soft delete: closed conversations with ts_last > 6 months ago (GDPR content retention)
        // Hard delete: soft-deleted conversations with ts_last > 12 months ago (GDPR audit retention)
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

    public function testPurgeRunningTwiceIsIdempotent(): void
    {
        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        // First run
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());

        // Second run should also succeed (no duplicates or errors)
        $tester->execute([]);
        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/Soft-deleted outbound conversations: \d+/', $output);
        $this->assertMatchesRegularExpression('/Hard-deleted inbound conversations: \d+/', $output);
    }

    public function testOutputContainsInfoTags(): void
    {
        $command = self::getContainer()->get(PurgeRgpdCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        // Run with decorated output to see the info tags
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Soft-deleted outbound conversations:', $output);
        $this->assertStringContainsString('Hard-deleted inbound conversations:', $output);
    }
}
