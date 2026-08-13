<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Conversation alternation invariant.
 *
 * The honeypot must alternate strictly inbound → outbound → inbound → outbound.
 * Two consecutive outbound replies break the human illusion. The check is enforced
 * at persistence time, unconditionally (force=true does NOT bypass it), and returns
 * a normal success response (no exception) carrying the existing outbound's data.
 */
class AlternationInvariantTest extends KernelTestCase
{
    private ReplyHandler $replyHandler;
    private ConversationHandler $conversationHandler;
    private MessageHandler $messageHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->replyHandler = $container->get(ReplyHandler::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->messageHandler = $container->get(MessageHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    /**
     * Create a conversation seeded with one inbound message.
     *
     * @return array{conv_id: string, msg_id: string}
     */
    private function createConversationWithInbound(): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($directionIn);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-alt-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $directionIn,
            'en',
            'Hello from scammer',
            'Please send funds.',
            '<p>Please send funds.</p>',
            [
                'from' => 'scammer@evil.test',
                'to' => 'victim@example.test',
                'message_id' => '<inb-' . bin2hex(random_bytes(8)) . '@evil.test>',
                'subject' => 'Hello from scammer',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('-30 minutes'),
            new \DateTimeImmutable('-30 minutes'),
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        return ['conv_id' => $conv->getConvId(), 'msg_id' => $msgId];
    }

    private function appendInbound(string $convId, string $bodySuffix = 'follow-up', int $minutesInFuture = 5): string
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($convId);
        $this->assertNotNull($channel);
        $this->assertNotNull($directionIn);
        $this->assertNotNull($conv);

        // The database stores ts_msg with second-precision (timestamp(0)). Pin the new
        // inbound to a timestamp strictly greater than any prior message in the same test
        // run, so the "latest message" gate observes it as the latest unambiguously.
        $ts = new \DateTimeImmutable('+' . $minutesInFuture . ' minutes');

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $directionIn,
            'en',
            'Re: scammer follow-up',
            'Did you receive the previous mail? ' . $bodySuffix,
            '<p>Follow-up content</p>',
            [
                'from' => 'scammer@evil.test',
                'to' => 'victim@example.test',
                'message_id' => '<follow-' . bin2hex(random_bytes(8)) . '@evil.test>',
                'subject' => 'Re: scammer follow-up',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            $ts,
            $ts,
            null
        );
        $this->em->persist($message);
        $this->em->flush();

        return $msgId;
    }

    private function countOutboundsInConversation(string $convId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.msgId)')
            ->from(Message::class, 'm')
            ->join('m.direction', 'd')
            ->where('m.conversation = :convId')
            ->andWhere('d.code = :out')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->setParameter('out', 'out')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Latest message is outbound → suppress + return existing data. */
    public function testGenerateReplyBlocksWhenLastMessageIsOutbound(): void
    {
        $data = $this->createConversationWithInbound();

        // First generate creates outbound1
        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->assertNotNull($result1);
        $this->assertSame(1, $this->countOutboundsInConversation($data['conv_id']));

        // Second generate -- latest message is now outbound1 -- must suppress
        $result2 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result2);
        $this->assertSame($result1['msg_id'], $result2['msg_id'], 'Second call must reuse existing outbound msg_id');
        $this->assertArrayHasKey('meta', $result2);
        $this->assertTrue(
            (bool) ($result2['meta']['duplicate_skipped'] ?? false),
            'Second call must carry duplicate_skipped=true in meta'
        );
        $this->assertSame(1, $this->countOutboundsInConversation($data['conv_id']), 'Only one outbound must exist in DB');
    }

    /** force=true does NOT bypass the alternation invariant. */
    public function testForceFalseDoesNotBypassAlternationInvariantWhenLastIsOutbound(): void
    {
        $data = $this->createConversationWithInbound();

        // First generate creates outbound1
        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->assertNotNull($result1);

        // Second generate with force=TRUE -- still suppressed because latest is outbound
        $result2 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], true, 'test');

        $this->assertNotNull($result2);
        $this->assertSame($result1['msg_id'], $result2['msg_id']);
        $this->assertTrue((bool) ($result2['meta']['duplicate_skipped'] ?? false));
        $this->assertSame(1, $this->countOutboundsInConversation($data['conv_id']));
    }

    /**
     * Neither waiver bypasses the alternation invariant, individually or together.
     *
     * The spacing waiver and the ceiling waiver are separate controls, and an
     * operator holding both still cannot make the platform send two consecutive
     * outbound messages: the invariant sits above every waiver. This is what makes
     * a waived spacing safe — a rapid exchange stays an exchange, never a burst.
     */
    public function testNoCombinationOfWaiversBypassesTheAlternationInvariant(): void
    {
        $data = $this->createConversationWithInbound();

        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->assertNotNull($result1);

        // Spacing waived AND ceilings waived — the strongest override available.
        $result2 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], true, 'test', true);

        $this->assertNotNull($result2);
        $this->assertSame($result1['msg_id'], $result2['msg_id'], 'No second outbound may be produced');
        $this->assertTrue((bool) ($result2['meta']['duplicate_skipped'] ?? false));
        $this->assertSame(1, $this->countOutboundsInConversation($data['conv_id']));
    }

    /** Latest is inbound → generation proceeds normally. */
    public function testGenerateReplyProceedsWhenLatestMessageIsInbound(): void
    {
        $data = $this->createConversationWithInbound();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result);
        $this->assertFalse((bool) ($result['meta']['duplicate_skipped'] ?? false));
        $this->assertSame(1, $this->countOutboundsInConversation($data['conv_id']));
    }

    /**
     * Edge case: latest by ts_msg is a soft-deleted outbound.
     * The "latest non-deleted" message must determine the gate.
     */
    public function testGenerateReplyIgnoresSoftDeletedOutboundWhenDeterminingLatest(): void
    {
        $data = $this->createConversationWithInbound();
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($data['conv_id']);
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);
        $this->assertNotNull($conv);
        $this->assertNotNull($channel);
        $this->assertNotNull($directionOut);

        // Insert a soft-deleted outbound dated AFTER the inbound (so it would be "latest" if not deleted)
        $deletedOutboundId = uuid_create(UUID_TYPE_RANDOM);
        $deletedOutbound = new Message(
            $deletedOutboundId,
            $conv,
            $channel,
            $directionOut,
            'en',
            'Re: Hello from scammer',
            'Old reply (deleted).',
            '<p>Old reply (deleted).</p>',
            [
                'from' => 'victim@example.test',
                'to' => 'scammer@evil.test',
                'send_status' => 'draft',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('-5 minutes'),
            new \DateTimeImmutable('-5 minutes'),
            new \DateTimeImmutable('-1 minute')  // deletedAt — soft-deleted
        );
        $this->em->persist($deletedOutbound);
        $this->em->flush();

        // Generate must proceed because the only non-deleted message is the inbound
        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result);
        $this->assertFalse((bool) ($result['meta']['duplicate_skipped'] ?? false));
        $this->assertNotSame($deletedOutboundId, $result['msg_id']);
    }

    /**
     * Realistic alternation sequence:
     *   inbound1 → outbound1 → inbound2 → outbound2 → inbound3 → outbound3
     * Each generate must succeed because the latest message is always inbound at trigger time.
     */
    public function testAlternationSequenceAllowsMultipleOutboundsWhenSeparatedByInbounds(): void
    {
        $data = $this->createConversationWithInbound();

        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->assertFalse((bool) ($result1['meta']['duplicate_skipped'] ?? false));

        // Scammer replies → latest becomes inbound2 (forced 5 min in the future to win the tie-break)
        $inbound2 = $this->appendInbound($data['conv_id'], 'round-2', 5);
        $result2 = $this->replyHandler->generateReply($data['conv_id'], $inbound2, true, 'test');
        $this->assertFalse((bool) ($result2['meta']['duplicate_skipped'] ?? false));
        $this->assertNotSame($result1['msg_id'], $result2['msg_id']);

        // Scammer replies again → latest becomes inbound3 (10 min in the future)
        $inbound3 = $this->appendInbound($data['conv_id'], 'round-3', 10);
        $result3 = $this->replyHandler->generateReply($data['conv_id'], $inbound3, true, 'test');
        $this->assertFalse((bool) ($result3['meta']['duplicate_skipped'] ?? false));
        $this->assertNotSame($result2['msg_id'], $result3['msg_id']);

        $this->assertSame(3, $this->countOutboundsInConversation($data['conv_id']));
    }

    /** Response shape on suppression mirrors a normal success. */
    public function testSuppressedResponseCarriesAllExpectedFields(): void
    {
        $data = $this->createConversationWithInbound();

        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $result2 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result2);
        $this->assertArrayHasKey('msg_id', $result2);
        $this->assertArrayHasKey('conv_id', $result2);
        $this->assertArrayHasKey('to', $result2);
        $this->assertArrayHasKey('subject', $result2);
        $this->assertArrayHasKey('draft', $result2);
        $this->assertArrayHasKey('meta', $result2);
        $this->assertIsArray($result2['draft']);
        $this->assertArrayHasKey('text', $result2['draft']);
        $this->assertArrayHasKey('html', $result2['draft']);

        // Suppressed response must point to the EXISTING outbound, not a fresh one
        $this->assertSame($result1['msg_id'], $result2['msg_id']);
        $this->assertSame($result1['conv_id'], $result2['conv_id']);
        $this->assertSame($result1['subject'], $result2['subject']);
    }
}
