<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\UI\Console\WeeklyCleanupCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class WeeklyCleanupCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testDryRunDoesNotModifyData(): void
    {
        // Make fixture conversation 002 eligible: closed + ts_last very old
        $this->connection->executeStatement(
            "UPDATE conversation SET ts_last = :old, deleted_at = NULL WHERE conv_id = '00000000-0000-0000-0000-000000000002'",
            ['old' => (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s')]
        );

        $countBefore = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE status = 'closed' AND deleted_at IS NULL"
        );

        $command = self::getContainer()->get(WeeklyCleanupCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Dry run mode', $output);
        $this->assertStringContainsString('Cleanup complete', $output);

        // Verify no rows were soft-deleted
        $countAfter = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM conversation WHERE status = 'closed' AND deleted_at IS NULL"
        );
        $this->assertSame($countBefore, $countAfter, 'Dry run should not modify any rows');
    }

    public function testActualCleanupSoftDeletesOldConversations(): void
    {
        // Make fixture conversation 002 eligible: closed, ts_last > 90 days ago, not deleted
        $this->connection->executeStatement(
            "UPDATE conversation SET ts_last = :old, deleted_at = NULL WHERE conv_id = '00000000-0000-0000-0000-000000000002'",
            ['old' => (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s')]
        );

        $command = self::getContainer()->get(WeeklyCleanupCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Conversations soft-deleted:', $output);
        $this->assertStringContainsString('Cleanup complete', $output);

        // Verify conversation 002 now has deleted_at set
        $deletedAt = $this->connection->fetchOne(
            "SELECT deleted_at FROM conversation WHERE conv_id = '00000000-0000-0000-0000-000000000002'"
        );
        $this->assertNotNull($deletedAt, 'Old closed conversation should have been soft-deleted');
        $this->assertNotFalse($deletedAt);
    }
}
