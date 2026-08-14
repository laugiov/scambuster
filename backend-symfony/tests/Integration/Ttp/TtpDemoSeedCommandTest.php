<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ttp;

use App\UI\Console\TtpDemoSeedCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use App\Domain\Communication\Ttp;

/**
 * Integration coverage of the demo TTP seeder.
 *
 * A dedicated INVOICE_FRAUD conversation is inserted with four controlled
 * messages: a strong-phrase inbound ("IBAN" → SB-T013, confirmed), a
 * weak-phrase inbound ("today" → SB-T017, review), a no-phrase inbound (no
 * observation) and an outbound carrying the strong phrase (never tagged). The
 * strong subject embeds a multibyte character before the phrase so the asserted
 * offsets are genuine UTF-8 code-point offsets, not byte offsets. All writes are
 * rolled back per test by DAMA.
 */
final class TtpDemoSeedCommandTest extends KernelTestCase
{
    private const CONV_TEMPLATE = '00000000-0000-0000-0000-000000000002';
    private const CONV = 'aaaaaaaa-0000-4000-8000-0000000000d1';

    private const MSG_STRONG = 'bbbbbbbb-0000-4000-8000-0000000000d1';
    private const MSG_WEAK = 'bbbbbbbb-0000-4000-8000-0000000000d2';
    private const MSG_NONE = 'bbbbbbbb-0000-4000-8000-0000000000d3';
    private const MSG_OUT = 'bbbbbbbb-0000-4000-8000-0000000000d4';

    // "Facture €" places a 3-byte / 1-code-point euro sign before the phrase, so
    // the code-point offset of "IBAN" (26) differs from its byte offset (28).
    private const STRONG_SUBJECT = 'Facture €';
    private const STRONG_BODY = 'Please wire to IBAN DE89 3704 0044 rapidement.';

    // Second conversation used by the rotation test.
    private const CONV2 = 'aaaaaaaa-0000-4000-8000-0000000000d2';

    private Connection $connection;

    private int $channelId;

    private int $dirIn;

    private int $dirOut;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        // Clean slate (DAMA rolls everything back after the test).
        $this->connection->executeStatement('DELETE FROM ttp_observation');

        $this->seedConversationWithMessages();
    }

    // ─── verbatim evidence + correct code-point offsets ─────────────────

    public function testStrongPhraseWritesVerbatimEvidenceWithCodePointOffsets(): void
    {
        $tester = $this->runCommand();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('APPLY (writes)', $tester->getDisplay());

        $row = $this->observationFor(self::MSG_STRONG);
        self::assertNotFalse($row, 'The strong-phrase inbound message must get an observation');

        self::assertSame('SB-T013', $row['code']);
        self::assertSame('confirmed', $row['status']);
        self::assertSame(0.85, (float) $row['confidence']);

        // Evidence is the verbatim, original-cased substring even though the
        // trigger phrase is stored lowercase.
        self::assertSame('IBAN', $row['evidence']);

        $analysed = self::STRONG_SUBJECT . "\n\n" . self::STRONG_BODY;
        $start = (int) $row['evidence_start'];
        $end = (int) $row['evidence_end'];

        // The offsets must slice the analysed text back to the exact evidence
        // (end exclusive) — the core code-point-offset correctness invariant.
        self::assertSame('IBAN', mb_substr($analysed, $start, $end - $start));
        self::assertSame(mb_strpos($analysed, 'IBAN'), $start);
        self::assertSame($start + mb_strlen('IBAN'), $end);

        // Code-point offset differs from the raw byte offset: proves mb offsets.
        self::assertNotSame(strpos($analysed, 'IBAN'), $start);
        self::assertSame(26, $start);
        self::assertSame(30, $end);

        // Provenance stamps.
        self::assertSame('demo-seed', $row['extraction_model']);
        self::assertSame('demo', $row['prompt_version']);
        self::assertSame(Ttp::TAXONOMY_VERSION, $row['taxonomy_version']);
    }

    // ─── confirmed / review split ───────────────────────────────────────

    public function testWeakPhraseIsReviewAndBothStatusesArePresent(): void
    {
        $this->runCommand();

        $weak = $this->observationFor(self::MSG_WEAK);
        self::assertNotFalse($weak, 'The weak-phrase inbound message must get an observation');
        self::assertSame('SB-T017', $weak['code']);
        self::assertSame('review', $weak['status']);
        self::assertSame(0.5, (float) $weak['confidence']);

        // Both statuses are represented on the two controlled messages.
        self::assertSame('confirmed', $this->observationFor(self::MSG_STRONG)['status'] ?? null);
        self::assertSame('review', $weak['status']);
    }

    // ─── no phrase → no observation ─────────────────────────────────────

    public function testMessageWithoutTriggerPhraseGetsNoObservation(): void
    {
        $this->runCommand();

        self::assertSame(0, $this->observationCountFor(self::MSG_NONE), 'A message with no trigger phrase must get no observation');
    }

    // ─── outbound never tagged ──────────────────────────────────────────

    public function testOutboundMessageIsNeverTagged(): void
    {
        $this->runCommand();

        self::assertSame(0, $this->observationCountFor(self::MSG_OUT), 'Outbound messages must never get an observation');
    }

    // ─── idempotence ────────────────────────────────────────────────────

    public function testSecondRunAddsNothing(): void
    {
        $this->runCommand();
        $afterFirst = $this->totalObservationCount();
        self::assertGreaterThan(0, $afterFirst);

        $this->runCommand();
        self::assertSame($afterFirst, $this->totalObservationCount(), 'A second run must not add rows');
    }

    // ─── dry-run writes nothing ─────────────────────────────────────────

    public function testDryRunWritesNothing(): void
    {
        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY-RUN (no writes)', $output);
        self::assertStringContainsString('no rows were written', $output);

        self::assertSame(0, $this->totalObservationCount(), 'Dry-run must write nothing');
        self::assertSame(0, $this->observationCountFor(self::MSG_STRONG));
    }

    // ─── purge clears then reseeds ──────────────────────────────────────

    public function testPurgeClearsThenReseeds(): void
    {
        $this->runCommand();
        $afterFirst = $this->totalObservationCount();
        self::assertGreaterThan(0, $afterFirst);

        $tester = $this->runCommand(['--purge' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('purge', $tester->getDisplay());

        // Purge deletes everything then reseeds deterministically: same total.
        self::assertSame($afterFirst, $this->totalObservationCount(), 'Purge + reseed must reproduce the same total');
        self::assertNotFalse($this->observationFor(self::MSG_STRONG), 'The strong observation must exist again after a purge reseed');
    }

    // ─── newly mapped phrase still writes verbatim evidence ─────────────

    public function testNewlyMappedPhraseIsTaggedWithVerbatimEvidence(): void
    {
        $msgId = 'cccccccc-0000-4000-8000-0000000000f1';
        $subject = 'Notice';
        $body = 'For your convenience, use our alternative payment method.';
        $this->insertMessage($msgId, $this->dirIn, self::CONV, $subject, $body);

        $this->runCommand();

        $row = $this->observationForCode($msgId, 'SB-T016');
        self::assertNotFalse($row, 'A message carrying a newly mapped phrase must get its observation');
        self::assertSame('alternative payment', $row['evidence']);

        $analysed = $subject . "\n\n" . $body;
        $start = (int) $row['evidence_start'];
        $end = (int) $row['evidence_end'];
        self::assertSame('alternative payment', mb_substr($analysed, $start, $end - $start));
        self::assertSame(mb_strpos($analysed, 'alternative payment'), $start);
        self::assertSame('demo-seed', $row['extraction_model']);
    }

    // ─── candidate rotation spreads the per-message cap ─────────────────

    public function testCandidateRotationTruncatesDifferentCodesByMessageIndex(): void
    {
        // A fresh INVOICE_FRAUD conversation with two identical inbound messages,
        // each carrying phrases for four leading candidates (SB-T003, SB-T005,
        // SB-T010, SB-T011). The per-message cap is 3, so exactly one code is
        // dropped — and the rotation makes it a DIFFERENT code by message index.
        $this->cloneInvoiceFraudConversation(self::CONV2);

        $body = 'Please review the invoice; our company registration is on file. '
            . 'Your account manager will assist. We apologize for the oversight.';
        $msg1 = 'cccccccc-0000-4000-8000-0000000000e1';
        $msg2 = 'cccccccc-0000-4000-8000-0000000000e2';
        $this->insertMessage($msg1, $this->dirIn, self::CONV2, 'Notice', $body);
        $this->insertMessage($msg2, $this->dirIn, self::CONV2, 'Notice', $body);

        $this->runCommand();

        $codes1 = $this->observedCodesFor($msg1);
        $codes2 = $this->observedCodesFor($msg2);

        self::assertCount(3, $codes1, 'The per-message cap must hold on message 1');
        self::assertCount(3, $codes2, 'The per-message cap must hold on message 2');

        // Message 1 (offset 0) keeps SB-T010 and truncates SB-T011.
        self::assertContains('SB-T010', $codes1);
        self::assertNotContains('SB-T011', $codes1);

        // Message 2 (rotated by the cap stride) keeps SB-T011 and truncates SB-T010.
        self::assertContains('SB-T011', $codes2);
        self::assertNotContains('SB-T010', $codes2);

        // Same body, different truncation — the rotation at work.
        self::assertNotSame($codes1, $codes2);
    }

    // ─── helpers ────────────────────────────────────────────────────────

    /**
     * @param array<string, bool|string> $input
     */
    private function runCommand(array $input = []): CommandTester
    {
        $command = self::getContainer()->get(TtpDemoSeedCommand::class);
        $app = new Application(self::$kernel);
        $app->add($command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function observationFor(string $msgId): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT o.evidence, o.evidence_start, o.evidence_end, o.confidence, o.status,
                    o.taxonomy_version, o.extraction_model, o.prompt_version, t.code
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.msg_id = :msgId',
            ['msgId' => $msgId]
        );
    }

    private function observationCountFor(string $msgId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ttp_observation WHERE msg_id = :msgId',
            ['msgId' => $msgId]
        );
    }

    private function totalObservationCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ttp_observation');
    }

    private function seedConversationWithMessages(): void
    {
        $this->channelId = (int) $this->connection->fetchOne(
            'SELECT channel_id FROM message WHERE conv_id = :conv AND channel_id IS NOT NULL LIMIT 1',
            ['conv' => self::CONV_TEMPLATE]
        );
        self::assertGreaterThan(0, $this->channelId, 'The template conversation must have a message to borrow a channel from');

        $this->dirIn = (int) $this->connection->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'in'");
        $this->dirOut = (int) $this->connection->fetchOne("SELECT dir_id FROM lkp_direction WHERE code = 'out'");

        $this->cloneInvoiceFraudConversation(self::CONV);

        $this->insertMessage(self::MSG_STRONG, $this->dirIn, self::CONV, self::STRONG_SUBJECT, self::STRONG_BODY);
        $this->insertMessage(self::MSG_WEAK, $this->dirIn, self::CONV, 'Reminder', 'Kindly confirm today.');
        $this->insertMessage(self::MSG_NONE, $this->dirIn, self::CONV, 'Greetings', 'Hello, I hope you are well.');
        $this->insertMessage(self::MSG_OUT, $this->dirOut, self::CONV, self::STRONG_SUBJECT, self::STRONG_BODY);
    }

    /**
     * Clone the fixture conversation, then pin it to INVOICE_FRAUD so its
     * candidate tactics are deterministic.
     */
    private function cloneInvoiceFraudConversation(string $convId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO conversation (conv_id, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, stix_id, created_at, updated_at, deleted_at, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types)
             SELECT :newId, primary_channel_id, scam_type_id, account_id, status, score_risk, ts_first, ts_last, :stix, created_at, updated_at, NULL, persona_id, engagement_duration_sec, turns_count, reward_value, delivery, tlp, secondary_scam_types
             FROM conversation WHERE conv_id = :template',
            ['newId' => $convId, 'stix' => 'stix-' . $convId, 'template' => self::CONV_TEMPLATE]
        );

        $invoiceFraudId = $this->connection->fetchOne("SELECT scam_type_id FROM lkp_scam_type WHERE code = 'INVOICE_FRAUD'");
        self::assertNotFalse($invoiceFraudId, 'The reference fixtures must seed INVOICE_FRAUD');
        $this->connection->executeStatement(
            'UPDATE conversation SET scam_type_id = :sid WHERE conv_id = :conv',
            ['sid' => (int) $invoiceFraudId, 'conv' => $convId]
        );
    }

    private function insertMessage(string $msgId, int $direction, string $convId, string $subject, string $body): void
    {
        $this->connection->executeStatement(
            'INSERT INTO message (msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text, headers, composite_hash, ts_msg, ts_ingest)
             VALUES (:msgId, :convId, :channelId, :direction, :lang, :subject, :body, :headers, :hash, NOW(), NOW())',
            [
                'msgId' => $msgId,
                'convId' => $convId,
                'channelId' => $this->channelId,
                'direction' => $direction,
                'lang' => 'en',
                'subject' => $subject,
                'body' => $body,
                'headers' => '{}',
                'hash' => bin2hex(random_bytes(32)),
            ]
        );
    }

    /**
     * @return list<string> distinct TTP codes observed on the given message
     */
    private function observedCodesFor(string $msgId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT t.code FROM ttp_observation o JOIN lkp_ttp t ON t.ttp_id = o.ttp_id WHERE o.msg_id = :msgId ORDER BY t.code',
            ['msgId' => $msgId]
        );

        return array_map(static fn ($c): string => (string) $c, $rows);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function observationForCode(string $msgId, string $code): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT o.evidence, o.evidence_start, o.evidence_end, o.status, o.extraction_model
             FROM ttp_observation o JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             WHERE o.msg_id = :msgId AND t.code = :code',
            ['msgId' => $msgId, 'code' => $code]
        );
    }
}
