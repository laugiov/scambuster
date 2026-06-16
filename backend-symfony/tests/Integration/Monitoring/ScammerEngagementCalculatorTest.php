<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Application\Monitoring\ScammerEngagementCalculator;
use App\Application\Monitoring\ScammerEngagementNoiseConfig;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 096 / C1 — Integration tests for the 3 biases.
 *
 * Each test injects controlled rows inside a transaction, runs the
 * Calculator (which sees the in-transaction data), then rolls back —
 * leaving the test DB untouched.
 *
 * The CRITICAL test is `testBias3FragmentationCountsReplyAcrossConvs`:
 * if it fails, the entire premise of spec 096 fails.
 */
final class ScammerEngagementCalculatorTest extends KernelTestCase
{
    private Connection $connection;
    private ScammerEngagementCalculator $calculator;

    private const COUNTERPART_MARKER = 'fix096-test-';
    private const HONEYPOT_MARKER = 'honeypot.fix096@scambuster.test';

    /** @var list<string> */
    private array $createdMsgIds = [];
    /** @var list<string> */
    private array $createdConvIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);

        $this->calculator = new ScammerEngagementCalculator(
            connection: $this->connection,
            noiseConfig: new ScammerEngagementNoiseConfig(),
            honeypotEmailAddresses: [self::HONEYPOT_MARKER],
        );

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
    }

    /**
     * Bias 1 — A conversation whose first inbound is a bounce or DMARC
     * report must be excluded from the metric (subject + sender patterns).
     */
    public function testBias1ExcludesBouncePostmasterDmarcConversations_096C1(): void
    {
        $baselineGlobal = $this->calculator->calculate()['global'];

        // Inject 3 noise conversations + 1 real conversation.
        // We make the real one observable (last_out > 96h ago) but unreplied.
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: 'mailer-daemon@example.com',
            firstInboundSubject: 'Undelivered Mail Returned to Sender',
            outboundTo: self::COUNTERPART_MARKER . 'noise1@test.tk',
            outboundAt: '5 days',
        );
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: 'noreply-dmarc@example.com',
            firstInboundSubject: 'Report Domain: example.com',
            outboundTo: self::COUNTERPART_MARKER . 'noise2@test.tk',
            outboundAt: '5 days',
        );
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: 'postmaster@example.com',
            firstInboundSubject: 'Delivery Status Notification',
            outboundTo: self::COUNTERPART_MARKER . 'noise3@test.tk',
            outboundAt: '5 days',
        );
        // Real conversation (5d old, unreplied) — should appear in observable
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: self::COUNTERPART_MARKER . 'real@scammer.test',
            firstInboundSubject: 'Hello, please pay invoice',
            outboundTo: self::COUNTERPART_MARKER . 'real@scammer.test',
            outboundAt: '5 days',
        );

        $after = $this->calculator->calculate()['global'];

        // Delta: only the real conv adds 1 observable, none responded
        $this->assertSame($baselineGlobal['observable'] + 1, $after['observable'], 'noise convs must NOT contribute to observable');
        $this->assertSame($baselineGlobal['responded'], $after['responded'], 'noise convs must NOT contribute to responded');
    }

    /**
     * Bias 2 — A counterpart whose last_out is more recent than
     * censoring_hours must be excluded from the denominator.
     */
    public function testBias2ExcludesRecentEngagements_096C1(): void
    {
        $baselineObservable = $this->calculator->calculate(censoringHours: 96)['global']['observable'];

        // Inject 1 conv engaged 1h ago — must NOT be observable at 96h
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: self::COUNTERPART_MARKER . 'recent@scammer.test',
            firstInboundSubject: 'Recent test',
            outboundTo: self::COUNTERPART_MARKER . 'recent@scammer.test',
            outboundAt: '1 hour',
        );
        // Inject 1 conv engaged 5d ago — must be observable
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: self::COUNTERPART_MARKER . 'old@scammer.test',
            firstInboundSubject: 'Old test',
            outboundTo: self::COUNTERPART_MARKER . 'old@scammer.test',
            outboundAt: '5 days',
        );

        $afterObservable = $this->calculator->calculate(censoringHours: 96)['global']['observable'];

        // Only the 5d-old one counts → +1
        $this->assertSame($baselineObservable + 1, $afterObservable, 'recently engaged counterpart must not be observable at 96h');
    }

    /**
     * Bias 3 — THE CRITICAL TEST. A scammer engaged in conv A, whose reply
     * arrives in conv B (because thread resolution failed), must still
     * count as "responded".
     *
     * Setup:
     *  - conv A: outbound to scammer@example.tk on day 0
     *  - conv B (new): inbound FROM scammer@example.tk on day 1 (after outbound)
     *  - The counterpart `scammer@example.tk` is the same in both.
     *
     * Without bias 3 correction (per-conv metric), conv A is "engaged
     * no-reply" and conv B is "engaged no-outbound". Both miscounted.
     * With bias 3 correction (per-counterpart), the counterpart is
     * observable AND responded.
     */
    public function testBias3FragmentationCountsReplyAcrossConvs_096C1(): void
    {
        $baseline = $this->calculator->calculate()['global'];

        $scammer = self::COUNTERPART_MARKER . 'bias3@scammer.test';

        // Conv A: scammer→us at day -5, our outbound→scammer at day -5
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: $scammer,
            firstInboundSubject: 'Conv A first contact',
            outboundTo: $scammer,
            outboundAt: '5 days',
        );

        // Conv B: scammer's reply landed in a NEW conv (thread broke).
        // We insert an INBOUND from $scammer in conv B at day -4 (1 day
        // after our outbound in conv A), and NO outbound in conv B.
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: $scammer,
            firstInboundSubject: 'Conv B (fragmented reply)',
            outboundTo: null,
            outboundAt: null,
            firstInboundAt: '4 days',
        );

        $after = $this->calculator->calculate()['global'];

        // The counterpart $scammer:
        // - has first_out (in conv A) at day -5
        // - has an inbound (in conv B) at day -4 > first_out → responded = TRUE
        // - last_out = day -5 < NOW() - 96h → observable = TRUE
        $this->assertSame($baseline['observable'] + 1, $after['observable'], 'bias 3 counterpart must be observable');
        $this->assertSame($baseline['responded'] + 1, $after['responded'], 'bias 3 reply in different conv MUST count as responded');
    }

    /**
     * Sanity — counterparts equal to a honeypot address are excluded.
     */
    /**
     * Regression — demo deploy bug 2026-06-16:
     * `'%env(default::csv:HONEYPOT_EMAIL_ADDRESSES)%'` returns NULL (not [])
     * when the env var is unset, because Symfony's csv: processor on an empty
     * default produces null. The previous constructor signature
     * (`private array $honeypotEmailAddresses = []`) rejected the null at
     * autowire time with a fatal TypeError, breaking the Impact dashboard.
     *
     * Contract: a null honeypot list must behave as "no honeypot filter",
     * not crash. Mirrors the tolerance already present in IocUpsertService
     * and CleanupPlatformContaminationCommand.
     */
    public function testNullHoneypotListIsTreatedAsEmptyFilter(): void
    {
        $calc = new ScammerEngagementCalculator(
            connection: $this->connection,
            noiseConfig: new ScammerEngagementNoiseConfig(),
            honeypotEmailAddresses: null,
        );

        $result = $calc->calculate();

        $this->assertSame(0, $result['params']['honeypot_addresses'], 'Null honeypot list must report 0 configured addresses.');
        $this->assertArrayHasKey('global', $result);
    }

    public function testExcludesHoneypotCounterpart_096C1(): void
    {
        $baselineObservable = $this->calculator->calculate()['global']['observable'];

        // Outbound to the honeypot itself (shouldn't happen in real life but
        // safety net). The counterpart for outbound = TO. If TO is a
        // honeypot, this counterpart is filtered out.
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: 'someone@example.com',
            firstInboundSubject: 'Self-loop',
            outboundTo: self::HONEYPOT_MARKER,
            outboundAt: '5 days',
        );

        $after = $this->calculator->calculate()['global']['observable'];
        $this->assertSame($baselineObservable, $after, 'outbound to a honeypot must not create an observable counterpart');
    }

    /**
     * Sanity — email normalization (display name + uppercase + spaces).
     */
    public function testNormalizesEmailAddressShapes_096C1(): void
    {
        $baseline = $this->calculator->calculate()['global'];

        // Use one logical counterpart expressed in 3 shapes.
        $variant1 = 'John Smith <' . self::COUNTERPART_MARKER . 'norm@x.com>';
        $variant2 = '  ' . strtoupper(self::COUNTERPART_MARKER) . 'NORM@X.COM  ';
        $variant3 = self::COUNTERPART_MARKER . 'norm@x.com';

        // Conv with outbound = variant1
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: $variant1,
            firstInboundSubject: 'Norm test',
            outboundTo: $variant2,
            outboundAt: '5 days',
        );

        // Inbound from variant3 in a different conv (acting as the reply)
        $this->seedConversation(
            scamTypeCode: 'INVOICE_FRAUD',
            firstInboundFrom: $variant3,
            firstInboundSubject: 'Norm test reply',
            outboundTo: null,
            outboundAt: null,
            firstInboundAt: '4 days',
        );

        $after = $this->calculator->calculate()['global'];
        // All 3 variants collapse to one counterpart → 1 observable, 1 responded
        $this->assertSame($baseline['observable'] + 1, $after['observable']);
        $this->assertSame($baseline['responded'] + 1, $after['responded']);
    }

    /**
     * Insert one synthetic conversation + 1 inbound (+ optionally 1 outbound).
     *
     * Returns the conv_id.
     */
    private function seedConversation(
        string $scamTypeCode,
        string $firstInboundFrom,
        string $firstInboundSubject,
        ?string $outboundTo,
        ?string $outboundAt,
        ?string $firstInboundAt = null,
    ): string {
        // Resolve scam_type_id from code
        $scamTypeId = $this->connection->fetchOne(
            'SELECT scam_type_id FROM lkp_scam_type WHERE code = :code',
            ['code' => $scamTypeCode],
        );
        if ($scamTypeId === false) {
            throw new \RuntimeException("scam_type code not found: {$scamTypeCode}");
        }

        $convId = $this->insertRandomConversation((int) $scamTypeId);
        $this->createdConvIds[] = $convId;

        // Inbound message
        $inboundOffset = $firstInboundAt ?? ($outboundAt ?? '5 days');
        $this->insertMessage(
            convId: $convId,
            direction: 1,
            headers: ['from' => $firstInboundFrom, 'to' => self::HONEYPOT_MARKER],
            subject: $firstInboundSubject,
            tsOffset: $inboundOffset,
        );

        // Outbound message (optional)
        if ($outboundTo !== null && $outboundAt !== null) {
            $this->insertMessage(
                convId: $convId,
                direction: 2,
                headers: ['from' => self::HONEYPOT_MARKER, 'to' => $outboundTo],
                subject: 'Re: ' . $firstInboundSubject,
                tsOffset: $outboundAt,
            );
        }

        return $convId;
    }

    private function insertRandomConversation(int $scamTypeId): string
    {
        $convId = $this->generateUuid();

        $personaId = (int) $this->connection->fetchOne('SELECT persona_id FROM persona LIMIT 1');
        $channelId = (int) $this->connection->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $accountId = $this->connection->fetchOne('SELECT account_id FROM mail_account LIMIT 1');
        if ($accountId === false) {
            throw new \RuntimeException('no mail_account row available for FK');
        }

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO conversation (
                conv_id, primary_channel_id, scam_type_id, persona_id, account_id, status,
                score_risk, ts_first, ts_last, created_at, updated_at, stix_id,
                delivery, tlp
            ) VALUES (
                :cid, :ch, :sid, :pid, :acc, 'open',
                50, NOW() - INTERVAL '5 days', NOW() - INTERVAL '5 days',
                NOW() - INTERVAL '5 days', NOW() - INTERVAL '5 days',
                :stix, 'received', 'amber'
            )
            SQL,
            [
                'cid' => $convId,
                'ch' => $channelId,
                'sid' => $scamTypeId,
                'pid' => $personaId,
                'acc' => $accountId,
                'stix' => 'fix096-test-' . $convId,
            ],
        );

        return $convId;
    }

    /**
     * @param array<string, string> $headers
     * @param 1|2 $direction Spec 096 uses `1`/`2` semantically (in/out) per
     *   the SQL CTE. The actual lkp_direction PK varies per DB —
     *   look it up by code.
     */
    private function insertMessage(string $convId, int $direction, array $headers, string $subject, string $tsOffset): void
    {
        $msgId = $this->generateUuid();
        $this->createdMsgIds[] = $msgId;

        $channelId = (int) $this->connection->fetchOne('SELECT channel_id FROM lkp_channel LIMIT 1');
        $code = $direction === 1 ? 'in' : 'out';
        $dirId = (int) $this->connection->fetchOne(
            'SELECT dir_id FROM lkp_direction WHERE code = :code',
            ['code' => $code],
        );

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO message (
                msg_id, conv_id, channel_id, direction, lang_detect, subject, body_text,
                headers, composite_hash, ts_msg, ts_ingest
            ) VALUES (
                :mid, :cid, :ch, :dir, 'en', :subj, 'integration test body',
                :hdr, :hash, NOW() - (:tsoff)::interval, NOW() - (:tsoff)::interval
            )
            SQL,
            [
                'mid' => $msgId,
                'cid' => $convId,
                'ch' => $channelId,
                'dir' => $dirId,
                'subj' => $subject,
                'hdr' => json_encode($headers),
                'hash' => bin2hex(random_bytes(32)),
                'tsoff' => $tsOffset,
            ],
        );
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
