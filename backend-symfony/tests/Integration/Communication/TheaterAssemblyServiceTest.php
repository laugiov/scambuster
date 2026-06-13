<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\IocHandler;
use App\Application\Communication\TheaterAssemblyService;
use App\Domain\Communication\Conversation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 097 / Slice 1 — Theater assembly integration tests.
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
            // Slice 1: revelation_context is null. Slice 2 will populate.
            $this->assertNull($ioc['revelation_context']);
            $this->assertContains($ioc['category'], ['financial', 'contact', 'infrastructure', 'other']);
        }
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
