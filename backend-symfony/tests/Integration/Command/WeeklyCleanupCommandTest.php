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

    private const ERASURE_CONV = '00000000-0000-0000-0000-000000000003';

    private function runCleanup(array $options = []): CommandTester
    {
        $command = self::getContainer()->get(WeeklyCleanupCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($options);

        return $tester;
    }

    /**
     * Make a fixture conversation eligible for permanent erasure: already
     * soft-deleted and past the twelve-month retention threshold.
     */
    private function makeErasureEligible(): void
    {
        $this->connection->executeStatement(
            'UPDATE conversation SET ts_last = :old, deleted_at = :del WHERE conv_id = :id',
            [
                'old' => (new \DateTimeImmutable('-400 days'))->format('Y-m-d H:i:s'),
                'del' => (new \DateTimeImmutable('-380 days'))->format('Y-m-d H:i:s'),
                'id' => self::ERASURE_CONV,
            ]
        );
    }

    private function conversationExists(string $convId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM conversation WHERE conv_id = :id',
            ['id' => $convId]
        ) > 0;
    }

    /**
     * The retention promise: permanent erasure is reported on every run so an
     * operator can see the volume, but nothing is erased without an explicit
     * authorisation. Omission must never delete.
     */
    public function testReportsErasureVolumeWithoutErasingAnything(): void
    {
        $this->makeErasureEligible();

        $convsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM conversation');
        $msgsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message');

        $tester = $this->runCleanup();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('eligible for permanent erasure', $tester->getDisplay());

        $this->assertSame(
            $convsBefore,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM conversation'),
            'A run without the erasure flag must not remove a single conversation'
        );
        $this->assertSame(
            $msgsBefore,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message'),
            'A run without the erasure flag must not remove a single message'
        );
        $this->assertTrue($this->conversationExists(self::ERASURE_CONV));
    }

    /**
     * The stage must respect the command's existing dry run like every other
     * stage, so "change nothing" keeps meaning nothing.
     */
    public function testDryRunAlsoSuppressesErasure(): void
    {
        $this->makeErasureEligible();

        $this->runCleanup(['--dry-run' => true, '--erase' => true]);

        $this->assertTrue(
            $this->conversationExists(self::ERASURE_CONV),
            'Dry run must win over the erasure flag'
        );
    }

    /**
     * Soft deletion already ran weekly before this stage existed, at its own
     * threshold. Adding erasure must not introduce a second, competing pass.
     */
    public function testSoftDeletionStillHappensExactlyOncePerRun(): void
    {
        $this->connection->executeStatement(
            "UPDATE conversation SET ts_last = :old, deleted_at = NULL, status = 'closed' WHERE conv_id = '00000000-0000-0000-0000-000000000002'",
            ['old' => (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s')]
        );

        $output = $this->runCleanup()->getDisplay();

        $this->assertSame(
            1,
            substr_count($output, 'Conversations soft-deleted:'),
            'Exactly one soft-deletion pass may run'
        );
    }

    /**
     * A system younger than the retention threshold has nothing to erase. Zero is
     * a valid result, not a failure.
     */
    public function testNothingEligibleReportsZeroAndSucceeds(): void
    {
        $this->connection->executeStatement(
            'UPDATE conversation SET deleted_at = NULL WHERE deleted_at IS NOT NULL'
        );

        $tester = $this->runCleanup();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('eligible for permanent erasure: 0', $tester->getDisplay());
    }

    /**
     * Erasure, when explicitly authorised, does remove the conversation and its
     * messages. Exercised on the dedicated test database only — never against a
     * database holding real data.
     */
    public function testExplicitAuthorisationErasesConversationAndItsMessages(): void
    {
        $this->makeErasureEligible();

        $msgsOfConv = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message WHERE conv_id = :id',
            ['id' => self::ERASURE_CONV]
        );
        $msgsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message');

        $this->runCleanup(['--erase' => true]);

        $this->assertFalse(
            $this->conversationExists(self::ERASURE_CONV),
            'Explicit authorisation must actually erase'
        );
        $this->assertSame(
            $msgsBefore - $msgsOfConv,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM message'),
            'Messages must be removed with their conversation, by the foreign-key cascade'
        );
    }

    public function testDryRunDoesNotModifyData(): void
    {
        $key = '__purge_dryrun_test__';
        $this->connection->executeStatement('DELETE FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]);

        try {
            // Make fixture conversation 002 eligible: closed + ts_last very old
            $this->connection->executeStatement(
                "UPDATE conversation SET ts_last = :old, deleted_at = NULL WHERE conv_id = '00000000-0000-0000-0000-000000000002'",
                ['old' => (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s')]
            );
            // An old terminal canary job — eligible for purge, so a real run WOULD delete it.
            $this->connection->executeStatement(
                'INSERT INTO prompt_canary_job (prompt_key, candidate_body, status, created_at) VALUES (:k, :b, :s, :c)',
                ['k' => $key, 'b' => 'dryrun keep', 's' => 'succeeded', 'c' => (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s')]
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

            // Verify no conversations were soft-deleted…
            $countAfter = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM conversation WHERE status = 'closed' AND deleted_at IS NULL"
            );
            $this->assertSame($countBefore, $countAfter, 'Dry run should not modify any rows');
            // …and the eligible canary job survives a dry run.
            $canaryCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]);
            $this->assertSame(1, $canaryCount, 'Dry run must not delete canary jobs');
        } finally {
            $this->connection->executeStatement('DELETE FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]);
        }
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

    public function testPurgesOldTerminalCanaryJobsButKeepsPendingAndRecent(): void
    {
        $key = '__purge_test__';
        $this->connection->executeStatement('DELETE FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]);

        try {
            $old = (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s');
            $recent = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
            $insert = 'INSERT INTO prompt_canary_job (prompt_key, candidate_body, status, created_at) VALUES (:k, :b, :s, :c)';
            $this->connection->executeStatement($insert, ['k' => $key, 'b' => 'old terminal', 's' => 'succeeded', 'c' => $old]);   // purge
            $this->connection->executeStatement($insert, ['k' => $key, 'b' => 'old pending', 's' => 'pending', 'c' => $old]);       // keep — not terminal
            $this->connection->executeStatement($insert, ['k' => $key, 'b' => 'recent done', 's' => 'succeeded', 'c' => $recent]);  // keep — too recent

            $command = self::getContainer()->get(WeeklyCleanupCommand::class);
            $app = new Application(self::$kernel);
            $app->add($command);
            $tester = new CommandTester($command);

            $tester->execute(['--canary-days' => '30']);

            $this->assertSame(0, $tester->getStatusCode());
            $this->assertStringContainsString('Prompt-canary jobs purged:', $tester->getDisplay());

            $bodies = array_column(
                $this->connection->fetchAllAssociative('SELECT candidate_body FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]),
                'candidate_body'
            );
            self::assertNotContains('old terminal', $bodies, 'an old terminal job must be purged');
            self::assertContains('old pending', $bodies, 'a pending job must never be purged, however old');
            self::assertContains('recent done', $bodies, 'a recent terminal job must be kept');
        } finally {
            $this->connection->executeStatement('DELETE FROM prompt_canary_job WHERE prompt_key = :k', ['k' => $key]);
        }
    }
}
