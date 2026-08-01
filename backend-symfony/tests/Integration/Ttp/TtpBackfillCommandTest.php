<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\Application\Communication\TtpManager;
use App\Application\LLM\TtpExtractor;
use App\Application\Ttp\TtpHandler;
use App\Application\Ttp\TtpObservationUpsertService;
use App\Domain\Communication\Policy\TtpExtractionPolicy;
use App\UI\Console\TtpBackfillCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration coverage of the TTP backfill command on the fixture dataset.
 *
 * The container's TtpHandler runs against the FakeLLMClient in the test env,
 * whose ttp_extraction branch always returns SB-T017 (0.92 -> confirmed) and
 * SB-T022 (0.4 -> review). Every processed inbound message therefore yields
 * exactly two observations, one of each status, deterministically and for free
 * (no real LLM, so llm_usage carries no cost). All writes are rolled back per
 * test by DAMA.
 */
final class TtpBackfillCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    // ─── preview / apply ───────────────────────────────────────────────

    public function testPreviewFindsObservationsButWritesNothing(): void
    {
        $before = $this->observationCount();
        $inScope = $this->inScopeInboundCount();
        self::assertGreaterThan(0, $inScope, 'The fixture dataset must contain in-scope inbound messages');

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();

        self::assertStringContainsString('PREVIEW (no writes)', $output);
        self::assertStringContainsString('SB-T017', $output);
        self::assertStringContainsString('SB-T022', $output);
        self::assertStringContainsString('no rows were written', $output);

        // Nothing was persisted despite observations being reported.
        self::assertSame($before, $this->observationCount(), 'Preview must not write any ttp_observation row');
    }

    public function testApplyPersistsObservationsWithConfirmedReviewSplit(): void
    {
        $before = $this->observationCount();
        $inScope = $this->inScopeInboundCount();

        $tester = $this->runCommand(['--apply' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('APPLY (writes)', $tester->getDisplay());

        // Two observations per processed message: one confirmed, one review.
        self::assertSame($before + (2 * $inScope), $this->observationCount());
        self::assertSame($inScope, $this->observationCountForCode('SB-T017'));
        self::assertSame($inScope, $this->observationCountForCode('SB-T022'));
        self::assertSame($inScope, $this->statusCount('confirmed'));
        self::assertSame($inScope, $this->statusCount('review'));
    }

    // ─── idempotence / resume ──────────────────────────────────────────

    public function testSecondApplyIsIdempotent(): void
    {
        $inScope = $this->inScopeInboundCount();

        $first = $this->runCommand(['--apply' => true]);
        self::assertSame(Command::SUCCESS, $first->getStatusCode());
        $afterFirst = $this->observationCount();
        self::assertSame(2 * $inScope, $afterFirst);

        // The scope excludes already-observed messages, so a re-run has nothing
        // to do and writes nothing further.
        $second = $this->runCommand(['--apply' => true]);
        self::assertSame(Command::SUCCESS, $second->getStatusCode());
        self::assertStringContainsString('No in-scope messages', $second->getDisplay());
        self::assertSame($afterFirst, $this->observationCount(), 'A second apply must not add rows');
    }

    // ─── force recompute ───────────────────────────────────────────────

    public function testForceRecomputesWithoutDoublingCount(): void
    {
        $inScope = $this->inScopeInboundCount();

        $this->runCommand(['--apply' => true]);
        $afterFirst = $this->observationCount();
        self::assertSame(2 * $inScope, $afterFirst);

        // --force re-includes already-observed messages, deletes their rows and
        // re-extracts: the count is stable, never doubled.
        $forced = $this->runCommand(['--apply' => true, '--force' => true]);
        self::assertSame(Command::SUCCESS, $forced->getStatusCode());
        self::assertStringContainsString('force (recompute)', $forced->getDisplay());
        self::assertSame($afterFirst, $this->observationCount(), 'Force recompute must not double observations');
    }

    // ─── limit ─────────────────────────────────────────────────────────

    public function testLimitCapsTheNumberProcessed(): void
    {
        $before = $this->observationCount();
        self::assertGreaterThanOrEqual(2, $this->inScopeInboundCount(), 'Need at least two in-scope messages to prove the cap');

        $tester = $this->runCommand(['--limit' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();

        self::assertStringContainsString('scope: 1 message(s)', $output);
        self::assertSame($before, $this->observationCount(), 'Preview writes nothing regardless of the cap');
    }

    // ─── scope exclusions ──────────────────────────────────────────────

    public function testOutgoingAndSoftDeletedMessagesAreNeverInScope(): void
    {
        $outbound = $this->connection->fetchOne(
            "SELECT m.msg_id FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'out'
             WHERE m.deleted_at IS NULL
             ORDER BY m.msg_id ASC LIMIT 1"
        );
        self::assertIsString($outbound, 'The fixture dataset must contain an outbound message');

        $softDeleted = $this->connection->fetchOne(
            'SELECT msg_id FROM message WHERE deleted_at IS NOT NULL ORDER BY msg_id ASC LIMIT 1'
        );
        self::assertIsString($softDeleted, 'The fixture dataset must contain a soft-deleted message');

        $tester = $this->runCommand(['--apply' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        self::assertSame(0, $this->observationCountForMessage($outbound), 'Outbound messages must never get an observation');
        self::assertSame(0, $this->observationCountForMessage($softDeleted), 'Soft-deleted messages must never get an observation');
    }

    // ─── conv-days scoping ─────────────────────────────────────────────

    public function testConvDaysNarrowsTheScope(): void
    {
        $full = $this->inScopeInboundCount();
        self::assertGreaterThan(0, $full, 'The fixture dataset must contain in-scope inbound messages');

        // The fixture conversations are all recently active, so age one in-scope
        // conversation into the past (DAMA rolls this back) to prove the window
        // filter excludes it while the default scope keeps it.
        $agedConv = $this->connection->fetchOne(
            "SELECT c.conv_id FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             LEFT JOIN ttp_observation o ON o.msg_id = m.msg_id
             WHERE m.deleted_at IS NULL AND o.obs_id IS NULL
             ORDER BY c.conv_id ASC LIMIT 1"
        );
        self::assertIsString($agedConv);

        $this->connection->executeStatement(
            'UPDATE conversation SET ts_last = :old WHERE conv_id = :conv',
            ['old' => (new \DateTimeImmutable('-100 days'))->format('Y-m-d H:i:s'), 'conv' => $agedConv]
        );

        $expected = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             LEFT JOIN ttp_observation o ON o.msg_id = m.msg_id
             WHERE m.deleted_at IS NULL AND o.obs_id IS NULL AND c.ts_last >= :cutoff",
            ['cutoff' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s')]
        );
        self::assertLessThan($full, $expected, 'Ageing one conversation must shrink the windowed scope');

        $tester = $this->runCommand(['--conv-days' => '3']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(sprintf('scope: %d message(s)', $expected), $tester->getDisplay());
        self::assertStringContainsString('active last 3 day(s)', $tester->getDisplay());
    }

    // ─── budget line ───────────────────────────────────────────────────

    public function testBudgetOptionIsReportedAndCostIsZeroUnderTheFakeLlm(): void
    {
        // The fake LLM records no llm_usage cost, so the real spend stays $0 and
        // the cap is never reached: this exercises the option parsing and the
        // cost/budget summary lines, not an actual budget stop.
        $tester = $this->runCommand(['--budget-usd' => '1.00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();

        self::assertStringContainsString('Real cost (USD)', $output);
        self::assertStringContainsString('$0.0000', $output);
        self::assertStringContainsString('$1.00', $output);
        self::assertStringContainsString('Budget stopped', $output);
    }

    // ─── disabled module ───────────────────────────────────────────────

    public function testDisabledModuleAbortsEarlyWithFailure(): void
    {
        self::assertGreaterThan(0, $this->inScopeInboundCount(), 'Need at least one in-scope message to reach the handler');
        $before = $this->observationCount();

        // A handler wired with the feature flag off refuses on the first message;
        // the disabled branch throws before touching any other dependency.
        $disabledHandler = new TtpHandler(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(TtpManager::class),
            self::getContainer()->get(TtpExtractor::class),
            new TtpObservationUpsertService($this->connection, new NullLogger()),
            new TtpExtractionPolicy(),
            new NullLogger(),
            null,
            enabled: false,
        );

        $command = new TtpBackfillCommand($this->connection, $disabledHandler, new NullLogger());
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute(['--apply' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('disabled', $tester->getDisplay());
        self::assertSame($before, $this->observationCount(), 'A disabled module must write nothing');
    }

    // ─── helpers ───────────────────────────────────────────────────────

    /**
     * @param array<string, bool|string> $input
     */
    private function runCommand(array $input = []): CommandTester
    {
        $command = self::getContainer()->get(TtpBackfillCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function inScopeInboundCount(): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             LEFT JOIN ttp_observation o ON o.msg_id = m.msg_id
             WHERE m.deleted_at IS NULL AND o.obs_id IS NULL"
        );
    }

    private function observationCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ttp_observation');
    }

    private function observationCountForCode(string $code): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation o JOIN lkp_ttp t ON t.ttp_id = o.ttp_id WHERE t.code = :code',
            ['code' => $code]
        );
    }

    private function observationCountForMessage(string $msgId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation WHERE msg_id = :msgId',
            ['msgId' => $msgId]
        );
    }

    private function statusCount(string $status): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation WHERE status = :status',
            ['status' => $status]
        );
    }
}
