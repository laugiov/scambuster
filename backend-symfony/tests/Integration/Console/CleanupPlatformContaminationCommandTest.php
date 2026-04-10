<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\UI\Console\CleanupPlatformContaminationCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Spec 061 — Sprint 2 — Cleanup command integration test.
 *
 * Sets up a controlled mix of clean + contaminated indicator/observed_ioc rows
 * (using direct DB inserts on the test DB), runs the cleanup command in
 * dry-run and real mode, asserts the expected before/after counts.
 *
 * Cleans up after itself so other tests are not affected.
 */
final class CleanupPlatformContaminationCommandTest extends KernelTestCase
{
    private Connection $conn;
    private string $testRunId;

    /** @var array<string, string> */
    private array $createdMsgIds = [];

    /** @var array<string, string> */
    private array $createdIndicatorIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->testRunId = bin2hex(random_bytes(4));
        $this->seedContaminatedFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupSeededRows();
        parent::tearDown();
    }

    /**
     * Insert a controlled fixture:
     *   - 1 incoming message with 1 clean indicator (legitimate scammer URL)
     *   - 1 outgoing message with 2 contaminated observations:
     *       a) the honeypot email
     *       b) a fictional 555 phone (LLM-invented in reply body)
     *   - 1 incoming message that ALSO observes the honeypot email (mixed-origin case)
     */
    private function seedContaminatedFixture(): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Resolve direction IDs
        $inDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");
        $outDir = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'out'");

        // Use existing fixture conversation + channel to satisfy FKs
        $convId = $this->conn->fetchOne('SELECT conv_id FROM conversation LIMIT 1');
        $channelId = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');

        // 3 messages
        $msgInClean = $this->insertMessage($convId, $channelId, $inDir, "spec061-{$this->testRunId}-in-clean", $now);
        $msgOutDirty = $this->insertMessage($convId, $channelId, $outDir, "spec061-{$this->testRunId}-out-dirty", $now);
        $msgInMixed = $this->insertMessage($convId, $channelId, $inDir, "spec061-{$this->testRunId}-in-mixed", $now);

        $this->createdMsgIds = [
            'in_clean' => $msgInClean,
            'out_dirty' => $msgOutDirty,
            'in_mixed' => $msgInMixed,
        ];

        // Indicator A: clean URL (only on incoming) — must NOT be deleted
        $indA = $this->insertIndicator('url', "spec061-{$this->testRunId}-clean.example", $now);
        $this->insertObserved($msgInClean, $indA);

        // Indicator B: honeypot email (only on outgoing) — must be deleted entirely
        $indB = $this->insertIndicator('email', "honeypot-spec061-{$this->testRunId}@example.com", $now);
        $this->insertObserved($msgOutDirty, $indB);

        // Indicator C: 555 phone (only on outgoing) — must be deleted entirely
        $indC = $this->insertIndicator('phone', "+1555{$this->testRunId}99", $now);
        $this->insertObserved($msgOutDirty, $indC);

        // Indicator D: mixed-origin email — observed on BOTH an outgoing and an incoming message
        // After cleanup: indicator must SURVIVE, but its outgoing observation must be deleted.
        $indD = $this->insertIndicator('email', "mixed-spec061-{$this->testRunId}@example.com", $now);
        $this->insertObserved($msgOutDirty, $indD);
        $this->insertObserved($msgInMixed, $indD);

        $this->createdIndicatorIds = [
            'A_clean' => $indA,
            'B_honeypot' => $indB,
            'C_555' => $indC,
            'D_mixed' => $indD,
        ];
    }

    private function insertMessage(string $convId, mixed $channelId, mixed $dirId, string $extId, string $now): string
    {
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $this->conn->executeStatement(
            "INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text,
                headers, composite_hash, ts_msg, ts_ingest, external_message_id)
             VALUES (:msgId, :convId, :channelId, :direction, 'en', 'spec061 test', 'body',
                '{}'::json, :hash, :ts, :ts, :extId)",
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'channelId' => $channelId,
                'direction' => $dirId,
                'hash' => bin2hex(random_bytes(32)),
                'ts' => $now,
                'extId' => $extId,
            ]
        );

        return $msgId;
    }

    private function insertIndicator(string $type, string $value, string $now): string
    {
        $id = uuid_create(UUID_TYPE_RANDOM);
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen,
                occurrences, tlp, created_at, updated_at)
             VALUES (:id, :type, :value, :value, :ts, :ts, 1, 'AMBER', :ts, :ts)",
            ['id' => $id, 'type' => $type, 'value' => $value, 'ts' => $now]
        );

        return $id;
    }

    private function insertObserved(string $msgId, string $indicatorId): void
    {
        $this->conn->executeStatement(
            "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, context_observation, ts_observed)
             VALUES (:obsId, :msgId, :indId, '{}'::json, NOW())",
            [
                'obsId' => uuid_create(UUID_TYPE_RANDOM),
                'msgId' => $msgId,
                'indId' => $indicatorId,
            ]
        );
    }

    private function cleanupSeededRows(): void
    {
        if (!empty($this->createdIndicatorIds)) {
            $ids = array_values($this->createdIndicatorIds);
            $this->conn->executeStatement(
                'DELETE FROM observed_ioc WHERE indicator_id IN (?)',
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
            $this->conn->executeStatement(
                'DELETE FROM indicator WHERE indicator_id IN (?)',
                [$ids],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }

        if (!empty($this->createdMsgIds)) {
            $this->conn->executeStatement(
                'DELETE FROM message WHERE msg_id IN (?)',
                [array_values($this->createdMsgIds)],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }
    }

    private function runCommand(array $input = []): CommandTester
    {
        $command = self::getContainer()->get(CleanupPlatformContaminationCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    public function testDryRunReportsCountsButDeletesNothing(): void
    {
        $beforeIndicators = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM indicator');
        $beforeObservations = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM observed_ioc');

        $tester = $this->runCommand([
            '--dry-run' => true,
            '--no-csv' => true,
            '--honeypot-address' => ["honeypot-spec061-{$this->testRunId}@example.com"],
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('DRY RUN', $output);

        // Database state must be unchanged
        $afterIndicators = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM indicator');
        $afterObservations = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM observed_ioc');

        $this->assertSame($beforeIndicators, $afterIndicators, 'Dry run must not delete any indicator');
        $this->assertSame($beforeObservations, $afterObservations, 'Dry run must not delete any observed_ioc');
    }

    public function testRealRunDeletesContaminationAndPreservesCleanData(): void
    {
        $tester = $this->runCommand([
            '--no-csv' => true,
            '--no-confirm' => true,
            '--honeypot-address' => ["honeypot-spec061-{$this->testRunId}@example.com"],
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        // Indicator A (clean URL on incoming): MUST still exist
        $aExists = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $this->createdIndicatorIds['A_clean']]
        );
        $this->assertSame(1, $aExists, 'Clean indicator A must survive cleanup');

        // Indicator B (honeypot email, only outgoing): MUST be deleted
        $bExists = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $this->createdIndicatorIds['B_honeypot']]
        );
        $this->assertSame(0, $bExists, 'Honeypot indicator B must be deleted');

        // Indicator C (555 phone, only outgoing): MUST be deleted (orphan after observed_ioc removal)
        $cExists = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $this->createdIndicatorIds['C_555']]
        );
        $this->assertSame(0, $cExists, 'Orphan 555 phone indicator C must be deleted');

        // Indicator D (mixed-origin email): MUST survive, but only its incoming observation remains
        $dExists = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM indicator WHERE indicator_id = :id',
            ['id' => $this->createdIndicatorIds['D_mixed']]
        );
        $this->assertSame(1, $dExists, 'Mixed-origin indicator D must survive (still has incoming observation)');

        $dObservationsRemaining = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM observed_ioc WHERE indicator_id = :id',
            ['id' => $this->createdIndicatorIds['D_mixed']]
        );
        $this->assertSame(1, $dObservationsRemaining, 'Mixed indicator D must keep exactly its incoming observation');
    }

    public function testIdempotencySecondRunIsNoOp(): void
    {
        // First run: real cleanup
        $this->runCommand([
            '--no-csv' => true,
            '--no-confirm' => true,
            '--honeypot-address' => ["honeypot-spec061-{$this->testRunId}@example.com"],
        ]);

        $afterFirstObservations = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM observed_ioc');
        $afterFirstIndicators = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM indicator');

        // Second run: must report zero deletes
        $tester = $this->runCommand([
            '--no-csv' => true,
            '--no-confirm' => true,
            '--honeypot-address' => ["honeypot-spec061-{$this->testRunId}@example.com"],
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $afterSecondObservations = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM observed_ioc');
        $afterSecondIndicators = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM indicator');

        $this->assertSame($afterFirstObservations, $afterSecondObservations);
        $this->assertSame($afterFirstIndicators, $afterSecondIndicators);
    }
}
