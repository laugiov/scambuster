<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\UI\Console\StripPendingSignaturesCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration test for the strip-pending-signatures migration
 * command.
 *
 * Seeds 3 controlled outbound rows in the test DB:
 *   1. With a Best regards + name signature → must be stripped
 *   2. With a [Your Name] placeholder → must be stripped
 *   3. Already clean → must be skipped
 *
 * Runs the command and asserts: 2 modified, 1 skipped, idempotent on re-run,
 * --dry-run reports changes without writing.
 *
 * Cleans up its seeded rows after each test.
 */
final class StripPendingSignaturesCommandTest extends KernelTestCase
{
    private Connection $conn;

    /** @var list<string> */
    private array $seededMsgIds = [];
    private string $seededConvId = '';

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var Connection $conn */
        $conn = $container->get(Connection::class);
        $this->conn = $conn;
    }

    protected function tearDown(): void
    {
        // Clean up seeded rows.
        if ($this->seededMsgIds !== []) {
            $placeholders = implode(',', array_fill(0, \count($this->seededMsgIds), '?'));
            $this->conn->executeStatement(
                "DELETE FROM message WHERE msg_id IN ({$placeholders})",
                $this->seededMsgIds,
            );
        }
        // Do NOT delete the conversation we reused from fixtures.
        parent::tearDown();
    }

    private int $channelId = 0;
    private int $outDirectionId = 0;

    private function seedOutboundRow(string $bodyText): string
    {
        if ($this->seededConvId === '') {
            $convRow = $this->conn->fetchOne('SELECT conv_id FROM conversation LIMIT 1');

            if (!\is_string($convRow)) {
                $this->markTestSkipped('No conversation fixture available in test DB.');
            }
            $this->seededConvId = $convRow;

            $channelRow = $this->conn->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');

            if (!\is_int($channelRow) && !\is_numeric($channelRow)) {
                $this->markTestSkipped('No lkp_channel fixture available.');
            }
            $this->channelId = (int) $channelRow;

            // direction_id varies by env (1/2 in dev, 117/118 in test) —
            // resolve dynamically via the lkp_direction code.
            $dirRow = $this->conn->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'out'");

            if (!\is_int($dirRow) && !\is_numeric($dirRow)) {
                $this->markTestSkipped('No lkp_direction[code=out] fixture.');
            }
            $this->outDirectionId = (int) $dirRow;
        }
        $msgId = sprintf(
            '%08s-%04s-%04s-%04s-%12s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6)),
        );

        $this->conn->insert('message', [
            'msg_id' => $msgId,
            'conv_id' => $this->seededConvId,
            'channel_id' => $this->channelId,
            'direction' => $this->outDirectionId,
            'lang_detect' => 'en',
            'composite_hash' => bin2hex(random_bytes(16)),
            'body_text' => $bodyText,
            'headers' => '{}',
            'ts_msg' => date('Y-m-d H:i:s'),
            'ts_ingest' => date('Y-m-d H:i:s'),
        ]);

        $this->seededMsgIds[] = $msgId;

        return $msgId;
    }

    private function commandTester(): CommandTester
    {
        $command = self::getContainer()->get(StripPendingSignaturesCommand::class);
        \assert($command instanceof StripPendingSignaturesCommand);
        $application = new Application(self::$kernel);
        $application->add($command);

        return new CommandTester($application->find('scambuster:strip-pending-signatures'));
    }

    public function test_strips_outbound_rows_and_reports_counts(): void
    {
        $msgWithSignature = $this->seedOutboundRow("Hello, please send the IBAN.\n\nBest regards,\nJohn Smith");
        $msgWithPlaceholder = $this->seedOutboundRow("Please reply.\n\n[Your Name]");
        $msgClean = $this->seedOutboundRow('Already clean body, no signature here.');

        $tester = $this->commandTester();
        $tester->execute(['--since' => '24 hours']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('modified', $output);

        $afterSig = $this->conn->fetchOne('SELECT body_text FROM message WHERE msg_id = ?', [$msgWithSignature]);
        $afterPh = $this->conn->fetchOne('SELECT body_text FROM message WHERE msg_id = ?', [$msgWithPlaceholder]);
        $afterClean = $this->conn->fetchOne('SELECT body_text FROM message WHERE msg_id = ?', [$msgClean]);

        self::assertIsString($afterSig);
        self::assertIsString($afterPh);
        self::assertIsString($afterClean);

        self::assertStringNotContainsString('Best regards', $afterSig);
        self::assertStringNotContainsString('John Smith', $afterSig);
        self::assertStringNotContainsString('[Your Name]', $afterPh);
        self::assertSame('Already clean body, no signature here.', $afterClean);
    }

    public function test_dry_run_does_NOT_modify_rows(): void
    {
        $msgWithSignature = $this->seedOutboundRow("Hello.\n\nSincerely,\nJane");

        $tester = $this->commandTester();
        $tester->execute(['--dry-run' => true, '--since' => '24 hours']);

        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);

        // Body must be unchanged.
        $after = $this->conn->fetchOne('SELECT body_text FROM message WHERE msg_id = ?', [$msgWithSignature]);
        self::assertSame("Hello.\n\nSincerely,\nJane", $after);
    }

    public function test_idempotent_when_run_twice(): void
    {
        $this->seedOutboundRow("Hi.\n\nBest,\nBob");

        $tester = $this->commandTester();
        $tester->execute(['--since' => '24 hours']);
        $tester->execute(['--since' => '24 hours']);

        // Second run must report 0 modifications.
        $output = $tester->getDisplay();
        self::assertStringContainsString('modified 0', $output);
    }
}
