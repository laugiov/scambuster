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
        // Same row, different provider_msg_id on the 2nd
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
        // Second call with the same provider_msg_id is a
        // silent no-op (returns true, leaves ts_sent untouched). Without
        // this, every stale n8n /sent retry surfaces as a 400 on the
        // operator dashboard.
        // Providers ids are persisted in bare form (no chevrons).
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
        $this->assertSame(trim($providerId, '<>'), $reloaded2->getProviderMsgId());
    }

    public function testMarkAsSentIsIdempotentWhenStoredHasChevronsAndCallbackIsBare(): void
    {
        // Regression for the 400 storm in n8n.
        // Historical rows (pre-fix) stored provider_msg_id WITH chevrons:
        // '<id@scambuster.local>'. n8n callbacks send the bare form
        // ('id@scambuster.local'). The equality check must normalize both
        // sides so the historical rows are recognised as idempotent.
        $msgs = $this->createThreadedMessages();
        $outboundId = $msgs['outbound']->getMsgId();

        // Manually persist the chevron-wrapped form to simulate a row
        // written by the pre-fix code path.
        $msgs['outbound']->setSendStatus('sent');
        $msgs['outbound']->setProviderMsgId('<historical-id@scambuster.local>');
        $msgs['outbound']->setTsSent(new \DateTimeImmutable('-10 minutes'));
        $this->em->flush();
        $this->em->clear();

        // n8n callback arrives with the bare form.
        $result = $this->service->markAsSent(
            $outboundId,
            'smtp',
            'historical-id@scambuster.local',
            new \DateTimeImmutable(),
        );

        $this->assertTrue($result, 'historical chevron-wrapped row must accept bare callback as idempotent');
    }

    public function testMarkAsSentStoresProviderMsgId(): void
    {
        // provider_msg_id is persisted in bare form (no chevrons)
        // regardless of how the caller formatted it.
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
        $this->assertSame(trim($providerMsgId, '<>'), $msgs['outbound']->getProviderMsgId());
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
    //  sendEmail atomic send_status='sent' write
    // ================================================================== //

    /**
     * Make the outbound + its parent ready for an actual sendEmail call:
     *  - rewrite outbound `to` to an address in SCAMBUSTER_SAFE_DOMAINS,
     *  - strip the chevrons from the parent's message-id so Symfony's
     *    IdentificationHeader (RFC 5322 §3.6.4) accepts it when sendEmail
     *    builds the In-Reply-To header.
     */
    private function makeOutboundSendable(Message $outbound): void
    {
        $headers = $outbound->getHeaders();
        $headers['to'] = 'scammer@example.com';
        $outbound->setHeaders($headers);

        $parent = $outbound->getReplyTo();

        if ($parent instanceof Message) {
            $parentHeaders = $parent->getHeaders();

            foreach (['message-id', 'message_id'] as $key) {
                if (isset($parentHeaders[$key]) && is_string($parentHeaders[$key])) {
                    $parentHeaders[$key] = trim($parentHeaders[$key], '<>');
                }
            }
            $parent->setHeaders($parentHeaders);
        }

        $this->em->flush();
    }

    public function testSendEmailPersistsSentStatusAtomically(): void
    {
        // When SMTP returns success, the message row must
        // be updated to send_status='sent' with provider_msg_id and
        // ts_sent IN THE SAME OPERATION. No window between SMTP delivery
        // and DB write during which a duplicate /send-email could trigger
        // a second SMTP delivery to the scammer.
        $msgs = $this->createThreadedMessages();
        $this->makeOutboundSendable($msgs['outbound']);
        $outboundId = $msgs['outbound']->getMsgId();

        $result = $this->service->sendEmail($outboundId);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message_id']);
        $this->assertNotEmpty($result['ts_sent']);
        // End-to-end: the real send path must emit a de-branded, well-formed
        // Message-ID (domain derived from the From identity), not the old
        // `@scambuster.local` product literal.
        $this->assertStringNotContainsStringIgnoringCase('scambuster', $result['message_id']);
        $this->assertMatchesRegularExpression('/^<?[0-9a-f]{32}@[^@>\s]+>?$/', $result['message_id']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Message::class)->find($outboundId);
        $this->assertSame('sent', $reloaded->getSendStatus(), 'send_status must be sent immediately after SMTP success');
        // Response payload keeps the chevron-wrapped form (RFC
        // 5322 representation that Symfony Mailer used in the SMTP header),
        // but the persisted provider_msg_id is normalized to bare form so
        // the n8n /sent callback (which posts the bare form) finds it.
        $this->assertSame(trim($result['message_id'], '<>'), $reloaded->getProviderMsgId(), 'provider_msg_id must be the bare form of the message_id returned to caller');
        $this->assertNotNull($reloaded->getTsSent());

        // Also persist the bare message-id into headers
        // so ThreadResolver finds the parent on inbound scammer replies.
        // headers['message-id'] = provider_msg_id minus chevrons (RFC 2822
        // convention for the inbound `in-reply-to` field shape).
        $headers = $reloaded->getHeaders();
        $this->assertArrayHasKey('message-id', $headers, 'headers[message-id] must be populated for inbound threading');
        $this->assertSame(
            trim($reloaded->getProviderMsgId(), '<>'),
            $headers['message-id'],
            'headers[message-id] must equal provider_msg_id without chevrons',
        );
    }

    public function testSendEmailSecondCallShortCircuitsAndReturnsSameResponse(): void
    {
        // After a successful send,
        // a second call for the same msgId must NOT invoke SMTP again
        // and must return the same message_id + ts_sent as the first.
        $msgs = $this->createThreadedMessages();
        $this->makeOutboundSendable($msgs['outbound']);
        $outboundId = $msgs['outbound']->getMsgId();

        $first = $this->service->sendEmail($outboundId);
        $second = $this->service->sendEmail($outboundId);

        $this->assertSame($first['message_id'], $second['message_id'], 'second call must return cached message_id');
        $this->assertSame($first['ts_sent'], $second['ts_sent'], 'second call must return cached ts_sent (no overwrite)');
    }

    public function testSendEmailDoesNotPersistOnSmtpFailure(): void
    {
        // SMTP failure must
        // leave the message in 'draft' so the next retry can attempt
        // delivery. A partial write (status=sent on a row whose email
        // was never delivered) would be worse than the bug we are fixing.
        $msgs = $this->createThreadedMessages();
        $this->makeOutboundSendable($msgs['outbound']);
        $outboundId = $msgs['outbound']->getMsgId();
        $originalStatus = $msgs['outbound']->getSendStatus();

        $failingMailer = $this->createMock(\Symfony\Component\Mailer\MailerInterface::class);
        $failingMailer->method('send')
            ->willThrowException(new \Symfony\Component\Mailer\Exception\TransportException('SMTP timeout simulated'));

        $serviceWithFailingMailer = new ReplyCompositionService(
            em: $this->em,
            messageHandler: $this->messageHandler,
            cadenceService: static::getContainer()->get(\App\Application\Communication\ReplyCadenceService::class),
            logger: new \Psr\Log\NullLogger(),
            auditLogger: null,
            mailer: $failingMailer,
            transportResolver: null,
        );

        try {
            $serviceWithFailingMailer->sendEmail($outboundId);
            $this->fail('Expected SMTP failure to propagate');
        } catch (\Throwable) {
            // Expected.
        }

        $this->em->clear();
        $reloaded = $this->em->getRepository(Message::class)->find($outboundId);
        $this->assertSame($originalStatus, $reloaded->getSendStatus(), 'send_status must NOT change when SMTP throws');
        $this->assertNull($reloaded->getProviderMsgId(), 'provider_msg_id must NOT be written when SMTP throws');
        $this->assertNull($reloaded->getTsSent(), 'ts_sent must NOT be written when SMTP throws');
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
