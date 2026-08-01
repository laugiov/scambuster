<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\TheaterAssemblyService;
use App\Application\Communication\TheaterHumanFactorCalculator;
use App\Application\Ttp\TtpObservationUpsertService;
use App\Application\Ttp\TtpQueryService;
use App\Domain\Communication\Conversation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Theater assembly integration tests.
 *
 * Each test wraps its mutations in a transaction + rollback so the
 * fixture DB stays intact across tests.
 */
final class TheaterAssemblyServiceTest extends KernelTestCase
{
    private TheaterAssemblyService $service;
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = self::getContainer()->get(Connection::class);
        $this->service = new TheaterAssemblyService(
            $this->em,
            self::getContainer()->get(IocHandler::class),
            $this->conn,
            new TheaterHumanFactorCalculator(),
            self::getContainer()->get(TtpQueryService::class),
        );

        $this->conn->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->conn->isTransactionActive()) {
            $this->conn->rollBack();
        }
    }

    public function testAssemblesBasicShape_097S1(): void
    {
        $conv = $this->pickConversationWithMessages();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with messages');
        }

        $payload = $this->service->assemble($conv);

        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('messages', $payload);
        $this->assertArrayHasKey('iocs_by_msg', $payload);
        $this->assertSame($conv->getConvId(), $payload['meta']['conv_id']);
        $this->assertGreaterThan(0, $payload['meta']['messages_count']);
        $this->assertCount($payload['meta']['messages_count'], $payload['messages']);
        $this->assertCount($payload['meta']['iocs_count'], $payload['iocs_by_msg']);
    }

    public function testMessagesAreOrderedAscByTsMsg_097S1(): void
    {
        $conv = $this->pickConversationWithMessages();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with messages');
        }

        $payload = $this->service->assemble($conv);

        $previousTs = null;
        foreach ($payload['messages'] as $idx => $msg) {
            $this->assertSame($idx + 1, $msg['idx']);
            $ts = strtotime($msg['ts_msg']);
            if (null !== $previousTs) {
                $this->assertGreaterThanOrEqual($previousTs, $ts);
            }
            $previousTs = $ts;
        }
    }

    public function testIocsDeduplicatedByValueNorm_097S1(): void
    {
        // Spec §Behavior rule #1 — first message wins on duplicate value_norm.
        $conv = $this->pickConversationWithIocs();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with IOCs');
        }

        $payload = $this->service->assemble($conv);

        $valueNorms = array_map(static fn (array $r): string => $r['value_norm'], $payload['iocs_by_msg']);
        $this->assertSame(count($valueNorms), count(array_unique($valueNorms)), 'value_norms must be unique');
    }

    public function testOrphanIocExcludedWhenParentMessageSoftDeleted_097S1(): void
    {
        // Spec §Behavior rule #8 — orphan IOC (parent msg deleted) MUST be excluded.
        $conv = $this->pickConversationWithIocs();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with IOCs');
        }

        $payload = $this->service->assemble($conv);
        $iocsBefore = $payload['meta']['iocs_count'];
        if ($iocsBefore < 1) {
            $this->markTestSkipped('Conv has no IOC to orphan');
        }

        // Pick the parent message of the first IOC and soft-delete it.
        $firstIocMsgId = $payload['iocs_by_msg'][0]['msg_id'];
        $this->conn->executeStatement(
            'UPDATE message SET deleted_at = NOW() WHERE msg_id = :id',
            ['id' => $firstIocMsgId],
        );

        // Reload the conv (entity may be cached) by refetching.
        $this->em->clear();
        $convRefreshed = $this->em->getRepository(Conversation::class)->find($conv->getConvId());
        $this->assertNotNull($convRefreshed);

        $payloadAfter = $this->service->assemble($convRefreshed);
        $this->assertLessThan($iocsBefore, $payloadAfter['meta']['iocs_count'], 'orphan IOC must be excluded');

        // The deleted msg must also be excluded from messages.
        foreach ($payloadAfter['messages'] as $msg) {
            $this->assertNotSame($firstIocMsgId, $msg['msg_id']);
        }
    }

    public function testLongConversationCapAt100Messages_097S1(): void
    {
        // Spec §Behavior rule #10 — convs with > 100 msgs are truncated to first 100.
        // We can't easily build a 100+ conv in fixtures, so we assert the constant
        // value and that the flag exists.
        $this->assertSame(100, TheaterAssemblyService::LONG_CONVERSATION_THRESHOLD);

        $conv = $this->pickConversationWithMessages();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv');
        }

        $payload = $this->service->assemble($conv);
        $this->assertArrayHasKey('long_conversation_truncated', $payload['meta']);
        // Fixture convs have < 100 msgs, so flag must be false.
        $this->assertFalse($payload['meta']['long_conversation_truncated']);
        $this->assertLessThanOrEqual(100, $payload['meta']['messages_count']);
    }

    public function testEachIocHasAllRequiredFields_097S1(): void
    {
        $conv = $this->pickConversationWithIocs();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with IOCs');
        }

        $payload = $this->service->assemble($conv);

        foreach ($payload['iocs_by_msg'] as $ioc) {
            foreach (['msg_id', 'obs_id', 'indicator_id', 'type', 'value', 'value_norm', 'category', 'ts_observed', 'revelation_context'] as $key) {
                $this->assertArrayHasKey($key, $ioc);
            }
            // Slice 2: revelation_context is either null OR an array with at least enrichment_status.
            $ctx = $ioc['revelation_context'];

            if (null !== $ctx) {
                $this->assertIsArray($ctx);
                $this->assertArrayHasKey('enrichment_status', $ctx);
            }
            $this->assertContains($ioc['category'], ['financial', 'contact', 'infrastructure', 'other']);
        }
    }

    // === revelation_context + human_factor integration ===

    public function testHumanFactorBlockShapeIsCorrect_097S2(): void
    {
        $conv = $this->pickConversationWithMessages();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv');
        }

        $payload = $this->service->assemble($conv);
        $this->assertArrayHasKey('human_factor', $payload);
        $hf = $payload['human_factor'];

        $this->assertArrayHasKey('deterministic', $hf);
        $this->assertArrayHasKey('exploratory_llm_signals', $hf);

        foreach (['total_turns', 'inbound_count', 'outbound_count', 'engagement_hours',
            'first_financial_turn', 'first_financial_ratio',
            'scammer_response_times_hours', 'scammer_response_time_hours_median',
            'cascade_events', 'language_switch_count', 'language_switch_turns',
            'persona_pressure_profile'] as $key) {
            $this->assertArrayHasKey($key, $hf['deterministic']);
        }

        foreach (['enrichment_coverage_pct', 'enrichment_confidence_avg',
            'enrichment_confidence_median', 'active_stimuli_count',
            'iocs_under_active_stimulus', 'avg_urgency_at_reveal',
            'hesitation_count'] as $key) {
            $this->assertArrayHasKey($key, $hf['exploratory_llm_signals']);
        }
    }

    public function testEnrichmentCoverageBetween0And100_097S2(): void
    {
        $conv = $this->pickConversationWithIocs();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with IOCs');
        }

        $payload = $this->service->assemble($conv);
        $pct = $payload['meta']['enrichment_coverage_pct'];
        $this->assertGreaterThanOrEqual(0.0, $pct);
        $this->assertLessThanOrEqual(100.0, $pct);
        // Cross-check: meta and human_factor sub-section agree on the value.
        $this->assertSame(
            $pct,
            $payload['human_factor']['exploratory_llm_signals']['enrichment_coverage_pct'],
        );
    }

    public function testRevelationContextStimulusMsgIdValidatedAgainstConv_097S2(): void
    {
        // Spec rule #9: stimulus_msg_id pointing outside conv → null in output.
        $conv = $this->pickConversationWithEnrichedIocs();
        if (null === $conv) {
            $this->markTestSkipped('No fixture conv with enriched IOCs');
        }

        $payload = $this->service->assemble($conv);
        $messageIdSet = array_flip(array_map(static fn (array $m): string => (string) $m['msg_id'], $payload['messages']));

        foreach ($payload['iocs_by_msg'] as $ioc) {
            $ctx = $ioc['revelation_context'];

            if (!\is_array($ctx) || 'enriched' !== ($ctx['enrichment_status'] ?? null)) {
                continue;
            }
            $stim = $ctx['stimulus_msg_id'] ?? null;

            if (null === $stim) {
                continue;
            }
            $this->assertArrayHasKey(
                (string) $stim,
                $messageIdSet,
                'stimulus_msg_id MUST belong to the conversation\'s messages (rule #9)',
            );
        }
    }

    public function testEmptyIocConvProducesValidHumanFactor_097S2(): void
    {
        // Spec edge case: conv with 0 IOCs still returns a valid human_factor block.
        $convId = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . ' WHERE c.deleted_at IS NULL'
            . ' AND EXISTS (SELECT 1 FROM message m WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL)'
            . ' AND NOT EXISTS ('
            . '   SELECT 1 FROM observed_ioc oi'
            . '   JOIN message m ON oi.msg_id = m.msg_id'
            . '   WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL'
            . ' ) LIMIT 1',
        );
        if (false === $convId) {
            $this->markTestSkipped('No fixture conv without IOCs');
        }

        $conv = $this->em->getRepository(Conversation::class)->find((string) $convId);
        $this->assertNotNull($conv);
        $payload = $this->service->assemble($conv);

        $this->assertSame(0, $payload['meta']['iocs_count']);
        $this->assertSame(0.0, $payload['meta']['enrichment_coverage_pct']);
        $this->assertSame(0, $payload['human_factor']['exploratory_llm_signals']['hesitation_count']);
        $this->assertNull($payload['human_factor']['exploratory_llm_signals']['avg_urgency_at_reveal']);
        $this->assertNull($payload['human_factor']['deterministic']['first_financial_turn']);
    }

    public function testConfirmedTtpsByMsgShapeAndConfirmedOnly_154(): void
    {
        $seed = $this->pickInboundMessageForSeeding();
        if (null === $seed) {
            $this->markTestSkipped('No fixture conv with an inbound message');
        }
        [$conv, $msgId] = $seed;

        // One confirmed + one review observation on the same inbound message.
        $this->seedTtp($conv->getConvId(), $msgId, 'SB-T001', 'confirmed', 0, 8);
        $this->seedTtp($conv->getConvId(), $msgId, 'SB-T005', 'review', null, null);

        $payload = $this->service->assemble($conv);

        $this->assertArrayHasKey('ttps_by_msg', $payload);
        $this->assertNotEmpty($payload['ttps_by_msg'], 'The seeded confirmed TTP must surface');

        $codes = [];

        foreach ($payload['ttps_by_msg'] as $ttp) {
            foreach (['msg_id', 'ttp_code', 'ttp_label', 'phase', 'confidence', 'status', 'evidence_start', 'evidence_end'] as $key) {
                $this->assertArrayHasKey($key, $ttp);
            }
            $this->assertSame('confirmed', $ttp['status'], 'Only confirmed TTPs belong in the theater payload');
            // Evidence stays offsets-only: no verbatim quote is ever serialized.
            $this->assertArrayNotHasKey('evidence', $ttp);
            $this->assertTrue(null === $ttp['evidence_start'] || \is_int($ttp['evidence_start']));
            $this->assertTrue(null === $ttp['evidence_end'] || \is_int($ttp['evidence_end']));
            $codes[] = $ttp['ttp_code'];
        }

        $this->assertContains('SB-T001', $codes, 'Confirmed TTP must be present');
        $this->assertNotContains('SB-T005', $codes, 'Review-status TTP must be excluded from the showcase');
    }

    public function testSoftDeletedMessageTtpIsExcluded_154(): void
    {
        // A confirmed TTP on a soft-deleted message must never surface.
        $seed = $this->pickInboundMessageForSeeding();
        if (null === $seed) {
            $this->markTestSkipped('No fixture conv with an inbound message');
        }
        [$conv, $msgId] = $seed;

        $this->seedTtp($conv->getConvId(), $msgId, 'SB-T001', 'confirmed', 0, 8);
        $this->conn->executeStatement('UPDATE message SET deleted_at = NOW() WHERE msg_id = :id', ['id' => $msgId]);

        $payload = $this->service->assemble($conv);

        $msgIds = array_map(static fn (array $r): string => (string) $r['msg_id'], $payload['ttps_by_msg']);
        $this->assertNotContains($msgId, $msgIds, 'A TTP on a soft-deleted message must be excluded');
    }

    public function testTruncationWindowTtpIsExcluded_154(): void
    {
        // The membership guard's unique contribution: a confirmed TTP on a
        // NON-deleted message that is sliced out of the first 100 (truncation
        // window) is excluded — the same $messageIdSet that scopes iocs_by_msg.
        // All mutations roll back with the surrounding transaction.
        $seed = $this->pickInboundMessageForSeeding();
        if (null === $seed) {
            $this->markTestSkipped('No fixture conv with an inbound message');
        }
        [$conv] = $seed;
        $convId = $conv->getConvId();

        // Append 101 non-deleted messages dated after the existing ones, so the
        // conversation crosses the 100-message truncation threshold.
        $this->conn->executeStatement(
            "INSERT INTO message
                (msg_id, conv_id, channel_id, direction, lang_detect, body_text, headers, ts_msg, ts_ingest, composite_hash)
             SELECT gen_random_uuid(), :conv, m0.channel_id, m0.direction, 'en',
                    'truncation body ' || g, '{}'::json,
                    NOW() + (g || ' minutes')::interval, NOW(), 'trunc-' || :conv || '-' || g
             FROM generate_series(1, 101) g
             CROSS JOIN (SELECT channel_id, direction FROM message WHERE conv_id = :conv LIMIT 1) m0",
            ['conv' => $convId],
        );

        // The last-dated appended message is guaranteed to sit beyond position 100.
        $lastMsgId = $this->conn->fetchOne(
            'SELECT msg_id::text FROM message WHERE conv_id = :conv AND deleted_at IS NULL ORDER BY ts_msg DESC, msg_id DESC LIMIT 1',
            ['conv' => $convId],
        );
        self::assertIsString($lastMsgId);

        $this->seedTtp($convId, $lastMsgId, 'SB-T001', 'confirmed', 0, 8);

        $payload = $this->service->assemble($conv);

        $this->assertTrue($payload['meta']['long_conversation_truncated'], 'The conversation must be truncated for this test to be meaningful');
        $msgIds = array_map(static fn (array $r): string => (string) $r['msg_id'], $payload['ttps_by_msg']);
        $this->assertNotContains($lastMsgId, $msgIds, 'A TTP on a message outside the truncation window must be excluded');
    }

    /**
     * @return array{0: Conversation, 1: string}|null [conv, inbound msg_id]
     */
    private function pickInboundMessageForSeeding(): ?array
    {
        $row = $this->conn->fetchAssociative(
            "SELECT m.conv_id::text AS conv_id, m.msg_id::text AS msg_id
             FROM message m
             JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'
             JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL
             WHERE m.deleted_at IS NULL
             ORDER BY m.msg_id ASC LIMIT 1",
        );

        if (false === $row) {
            return null;
        }

        $conv = $this->em->getRepository(Conversation::class)->find((string) $row['conv_id']);

        if (null === $conv) {
            return null;
        }

        return [$conv, (string) $row['msg_id']];
    }

    private function seedTtp(string $convId, string $msgId, string $code, string $status, ?int $start, ?int $end): void
    {
        $upsert = new TtpObservationUpsertService($this->conn, new NullLogger());
        self::assertTrue($upsert->upsert([
            'msg_id' => $msgId,
            'conv_id' => $convId,
            'ttp_id' => $this->ttpId($code),
            'confidence' => 'confirmed' === $status ? 0.9 : 0.4,
            'evidence' => sprintf('seeded evidence for %s', $code),
            'evidence_start' => $start,
            'evidence_end' => $end,
            'status' => $status,
            'taxonomy_version' => '1.0',
            'extraction_model' => 'test-model',
            'prompt_version' => 'v1',
        ]));
    }

    private function ttpId(string $code): int
    {
        $id = $this->conn->fetchOne('SELECT ttp_id FROM lkp_ttp WHERE code = :code', ['code' => $code]);
        self::assertIsNumeric($id, sprintf('Taxonomy code %s must exist in lkp_ttp', $code));

        return (int) $id;
    }

    private function pickConversationWithEnrichedIocs(): ?Conversation
    {
        $id = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . ' WHERE c.deleted_at IS NULL'
            . ' AND EXISTS ('
            . '   SELECT 1 FROM observed_ioc oi'
            . '   JOIN message m ON oi.msg_id = m.msg_id'
            . "   JOIN ioc_context ic ON ic.obs_id = oi.obs_id AND ic.enrichment_status = 'enriched'"
            . '   WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL'
            . ' ) LIMIT 1',
        );
        if (false === $id) {
            return null;
        }

        return $this->em->getRepository(Conversation::class)->find((string) $id);
    }

    private function pickConversationWithMessages(): ?Conversation
    {
        $id = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . ' WHERE c.deleted_at IS NULL'
            . ' AND EXISTS (SELECT 1 FROM message m WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL)'
            . ' LIMIT 1',
        );
        if (false === $id) {
            return null;
        }

        return $this->em->getRepository(Conversation::class)->find((string) $id);
    }

    private function pickConversationWithIocs(): ?Conversation
    {
        $id = $this->conn->fetchOne(
            'SELECT c.conv_id::text FROM conversation c'
            . ' WHERE c.deleted_at IS NULL'
            . ' AND EXISTS ('
            . '   SELECT 1 FROM observed_ioc oi'
            . '   JOIN message m ON oi.msg_id = m.msg_id'
            . '   WHERE m.conv_id = c.conv_id AND m.deleted_at IS NULL'
            . ' ) LIMIT 1',
        );
        if (false === $id) {
            return null;
        }

        return $this->em->getRepository(Conversation::class)->find((string) $id);
    }
}
