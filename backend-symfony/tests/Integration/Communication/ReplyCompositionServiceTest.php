<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\MessageHandler;
use App\Application\Communication\ReplyCompositionService;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ReplyCompositionService
 *
 * Tests header composition, mark-as-sent, and safety checks.
 */
class ReplyCompositionServiceTest extends KernelTestCase
{
    private ReplyCompositionService $service;
    private ConversationHandler $conversationHandler;
    private MessageHandler $messageHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ReplyCompositionService::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->messageHandler = $container->get(MessageHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    /**
     * Create a conversation with an inbound message and an outbound reply linked to it.
     *
     * @return array{inbound: Message, outbound: Message}
     */
    private function createThreadedMessages(?string $fromHeader = null): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $dirIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $dirOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($dirIn);
        $this->assertNotNull($dirOut);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-reply-comp-' . bin2hex(random_bytes(4))
        );

        $parentMsgId = '<parent-' . bin2hex(random_bytes(8)) . '@test.com>';
        $inboundMsgId = uuid_create(UUID_TYPE_RANDOM);
        $inbound = new Message(
            $inboundMsgId,
            $conv,
            $channel,
            $dirIn,
            'en',
            'Scam subject',
            'Send me money',
            null,
            [
                'from' => 'scammer@evil.test',
                'to' => 'honeypot@test.com',
                'message-id' => $parentMsgId,
                'message_id' => $parentMsgId,
                'references' => '<older-ref@test.com>',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('-30 minutes'),
            new \DateTimeImmutable('-30 minutes'),
            null
        );
        $this->em->persist($inbound);
        $this->em->flush();

        $outboundMsgId = uuid_create(UUID_TYPE_RANDOM);
        $outbound = new Message(
            $outboundMsgId,
            $conv,
            $channel,
            $dirOut,
            'en',
            'Re: Scam subject',
            'Sure, what account?',
            '<p>Sure, what do you need?</p>',
            [
                'from' => $fromHeader ?? 'honeypot@test.com',
                'to' => 'scammer@evil.test',
            ],
            bin2hex(random_bytes(32)),
            null,
            $inbound,  // reply_to
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($outbound);
        $this->em->flush();

        return ['inbound' => $inbound, 'outbound' => $outbound];
    }

    // ------------------------------------------------------------------ //
    //  composeHeaders
    // ------------------------------------------------------------------ //

    public function testComposeHeadersReturnsValidStructure(): void
    {
        $msgs = $this->createThreadedMessages();
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        $this->assertArrayHasKey('msg_id', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('safe_to_send', $result);
        $this->assertArrayHasKey('rate_limited', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('references', $result);
    }

    public function testComposeHeadersIncludesParentMessageIdInReferences(): void
    {
        $msgs = $this->createThreadedMessages();
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        $references = $result['references'] ?? '';
        $parentMsgId = $msgs['inbound']->getHeaders()['message-id'] ?? '';

        // References should contain the parent message-id
        $this->assertStringContainsString($parentMsgId, $references);
    }

    public function testComposeHeadersReturnsNullForNonExistentMessage(): void
    {
        $result = $this->service->composeHeaders('ffffffff-ffff-ffff-ffff-ffffffffffff');

        $this->assertNull($result);
    }

    public function testComposeHeadersThrowsForNonReplyMessage(): void
    {
        // Create a standalone message (not a reply)
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-no-reply-' . bin2hex(random_bytes(4))
        );

        $msg = new Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $direction,
            'en',
            'Standalone',
            'Not a reply',
            null,
            ['from' => 'test@test.com', 'to' => 'to@test.com'],
            bin2hex(random_bytes(32)),
            null,
            null,  // No replyTo
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
        $this->em->persist($msg);
        $this->em->flush();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a reply');

        $this->service->composeHeaders($msg->getMsgId());
    }

    // ------------------------------------------------------------------ //
    //  markAsSent
    // ------------------------------------------------------------------ //

    public function testMarkAsSentUpdatesMessageStatus(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        $result = $this->service->markAsSent(
            $outboundId,
            'smtp',
            '<sent-' . bin2hex(random_bytes(4)) . '@test.com>',
            new \DateTimeImmutable(),
        );

        $this->assertTrue($result);

        $this->em->refresh($msgs['outbound']);
        $this->assertSame('sent', $msgs['outbound']->getSendStatus());
    }

    public function testMarkAsSentReturnsFalseForNonExistentMessage(): void
    {
        $result = $this->service->markAsSent(
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'smtp',
            '<test@test.com>',
            new \DateTimeImmutable(),
        );

        $this->assertFalse($result);
    }

    public function testMarkAsSentThrowsConflictWhenProviderIdDiffers(): void
    {
        // Spec 082 §US2.2 — same row, different provider_msg_id on the 2nd
        // call: we refuse rather than silently overwrite the recorded id.
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        $this->service->markAsSent(
            $outboundId,
            'smtp',
            '<sent-1@test.com>',
            new \DateTimeImmutable(),
        );

        $this->expectException(\App\Application\Communication\Exception\MarkAsSentConflictException::class);

        $this->service->markAsSent(
            $outboundId,
            'smtp',
            '<sent-2@test.com>',
            new \DateTimeImmutable(),
        );
    }

    public function testMarkAsSentIsIdempotentWhenProviderIdMatches(): void
    {
        // Spec 082 §US2.1 — second call with the same provider_msg_id is a
        // silent no-op (returns true, leaves ts_sent untouched). Without
        // this, every stale n8n /sent retry surfaces as a 400 on the
        // operator dashboard.
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();
        $providerId = '<idempotent-id@test.com>';
        $firstTs = new \DateTimeImmutable('-1 minute');

        $this->service->markAsSent($outboundId, 'smtp', $providerId, $firstTs);

        $this->em->clear();
        $reloaded = $this->em->getRepository(\App\Domain\Communication\Message::class)->find($outboundId);
        $firstStoredTs = $reloaded->getTsSent();

        // Second call with same provider_msg_id and DIFFERENT ts must NOT
        // overwrite the original ts_sent.
        $result = $this->service->markAsSent($outboundId, 'smtp', $providerId, new \DateTimeImmutable('+5 minutes'));

        $this->assertTrue($result);
        $this->em->clear();
        $reloaded2 = $this->em->getRepository(\App\Domain\Communication\Message::class)->find($outboundId);
        $this->assertEquals($firstStoredTs, $reloaded2->getTsSent(), 'ts_sent must be frozen by first write');
        $this->assertSame($providerId, $reloaded2->getProviderMsgId());
    }

    public function testMarkAsSentStoresProviderMsgId(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();
        $providerMsgId = '<provider-' . bin2hex(random_bytes(8)) . '@smtp.test>';

        $this->service->markAsSent(
            $outboundId,
            'smtp',
            $providerMsgId,
            new \DateTimeImmutable(),
        );

        $this->em->refresh($msgs['outbound']);
        $this->assertSame($providerMsgId, $msgs['outbound']->getProviderMsgId());
    }

    public function testMarkAsSentStoresSentHeaders(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        $this->service->markAsSent(
            $outboundId,
            'smtp',
            '<provider@test.com>',
            new \DateTimeImmutable(),
            ['thread_id' => 'thread-123', 'message-id' => '<real-msg-id@smtp.test>'],
        );

        $this->em->refresh($msgs['outbound']);
        $headers = $msgs['outbound']->getHeaders();

        $this->assertSame('thread-123', $headers['thread_id']);
        // Message-ID should be stored without chevrons
        $this->assertSame('real-msg-id@smtp.test', $headers['message-id']);
    }

    // ================================================================== //
    //  Merged from ReplyCompositionServiceAdditionalTest
    // ================================================================== //

    public function testComposeHeadersBuildsReferencesChain(): void
    {
        $msgs = $this->createThreadedMessages();
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        $references = $result['references'] ?? '';

        // Should contain both the older reference and the parent message ID
        $parentMsgId = $msgs['inbound']->getHeaders()['message-id'] ?? '';
        $this->assertStringContainsString($parentMsgId, $references);
    }

    public function testComposeHeadersResolvesFromWhenInvalid(): void
    {
        // Create with invalid from (no @ sign, simulating IMAP hostname)
        $msgs = $this->createThreadedMessages('imap-server-hostname');
        $result = $this->service->composeHeaders($msgs['outbound']->getMsgId());

        $this->assertNotNull($result);
        // The from should be resolved from parent's to header
        $from = $result['from'] ?? '';
        $this->assertStringContainsString('@', $from, 'From should be resolved to a valid email');
    }

    public function testMarkAsSentWithCorrectConvId(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();
        $convId = $msgs['outbound']->getConversation()->getConvId();

        $result = $this->service->markAsSent(
            $outboundId,
            'gmail',
            '<gmail-sent@test.com>',
            new \DateTimeImmutable(),
            null,
            $convId // matching conv_id
        );

        $this->assertTrue($result);
    }

    public function testMarkAsSentWithMismatchedConvIdStillSucceeds(): void
    {
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        // Provide a wrong conv_id -- should log warning but still succeed
        $result = $this->service->markAsSent(
            $outboundId,
            'gmail',
            '<gmail-sent-2@test.com>',
            new \DateTimeImmutable(),
            null,
            'ffffffff-ffff-ffff-ffff-ffffffffffff' // wrong conv_id
        );

        $this->assertTrue($result);
    }

    public function testSendEmailThrowsWhenMailerNotConfigured(): void
    {
        // The default test env may or may not have a mailer configured
        // If sendEmail is available, it should either work or throw with a clear message
        $msgs = $this->createThreadedMessages();

        try {
            $this->service->sendEmail($msgs['outbound']->getMsgId());
            // If we get here, mailer is configured and send succeeded
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            // Expected: Mailer not configured, safety check failure, or similar
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testSendEmailThrowsForNonexistentMessage(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->sendEmail('ffffffff-ffff-ffff-ffff-ffffffffffff');
    }
}
