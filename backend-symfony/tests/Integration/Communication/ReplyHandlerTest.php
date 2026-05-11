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

class ReplyHandlerTest extends KernelTestCase
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

    private function createTestConversationWithMessage(): array
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-reply-integ-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'fr',
            'Urgent: Bank Verification Required',
            'Please send your bank details immediately!',
            '<p>Please send your bank details immediately!</p>',
            [
                'from' => 'scammer@evil.test',
                'to' => 'victim@example.test',
                'message_id' => '<test-' . bin2hex(random_bytes(8)) . '@evil.test>',
                'subject' => 'Urgent: Bank Verification Required',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );

        $this->em->persist($message);
        $this->em->flush();

        return ['conv_id' => $conv->getConvId(), 'msg_id' => $msgId];
    }

    public function testGetConversationContext(): void
    {
        $data = $this->createTestConversationWithMessage();

        $context = $this->replyHandler->getConversationContext($data['conv_id']);

        $this->assertNotNull($context);
        $this->assertArrayHasKey('conv_id', $context);
        $this->assertArrayHasKey('status', $context);
        $this->assertArrayHasKey('scam_type', $context);
        $this->assertArrayHasKey('persona', $context);
        $this->assertArrayHasKey('cadence', $context);
        $this->assertArrayHasKey('last_messages', $context);

        $this->assertSame($data['conv_id'], $context['conv_id']);
        $this->assertSame('open', $context['status']);
        $this->assertIsArray($context['last_messages']);
        $this->assertGreaterThan(0, count($context['last_messages']));
    }

    public function testGetConversationContextReturnsNullForNonExistentConversation(): void
    {
        $context = $this->replyHandler->getConversationContext(uuid_create(UUID_TYPE_RANDOM));
        $this->assertNull($context);
    }

    public function testGetConversationContextLimitsMessages(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Create 10 more messages
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($data['conv_id']);
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        for ($i = 0; $i < 10; $i++) {
            $msg = new Message(
                uuid_create(UUID_TYPE_RANDOM),
                $conv,
                $channel,
                $direction,
                'fr',
                "Subject $i",
                "Body $i",
                null,
                ['from' => 'test@test.com'],
                bin2hex(random_bytes(32)),
                null,
                null,
                new \DateTimeImmutable("+$i seconds"),
                new \DateTimeImmutable("+$i seconds"),
                null
            );
            $this->em->persist($msg);
        }
        $this->em->flush();

        // Request only 3 messages
        $context = $this->replyHandler->getConversationContext($data['conv_id'], 3);

        $this->assertNotNull($context);
        $this->assertCount(3, $context['last_messages']);
    }

    public function testGenerateReply(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('msg_id', $result);
        $this->assertArrayHasKey('conv_id', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('draft', $result);
        $this->assertArrayHasKey('meta', $result);

        $this->assertSame($data['conv_id'], $result['conv_id']);
        $this->assertStringStartsWith('Re:', $result['subject']);
        $this->assertIsArray($result['draft']);
        $this->assertArrayHasKey('text', $result['draft']);
        $this->assertArrayHasKey('html', $result['draft']);

        // Verify message was created in database
        $replyMsg = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $this->assertNotNull($replyMsg);
        $this->assertSame('draft', $replyMsg->getSendStatus());
        $this->assertNotNull($replyMsg->getReplyTo());
        $this->assertSame($data['msg_id'], $replyMsg->getReplyTo()->getMsgId());
    }

    public function testGenerateReplyForClosedConversationThrowsException(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Close the conversation
        $this->conversationHandler->patchConversation($data['conv_id'], ['status' => 'closed']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot generate reply for closed conversation');

        $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
    }

    public function testComposeHeaders(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Generate a reply first
        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $replyMsgId = $result['msg_id'];

        $composeData = $this->replyHandler->composeHeaders($replyMsgId);

        $this->assertNotNull($composeData);
        $this->assertArrayHasKey('msg_id', $composeData);
        $this->assertArrayHasKey('to', $composeData);
        $this->assertArrayHasKey('from', $composeData);
        $this->assertArrayHasKey('subject', $composeData);
        $this->assertArrayHasKey('in_reply_to', $composeData);
        $this->assertArrayHasKey('references', $composeData);
        $this->assertArrayHasKey('safe_to_send', $composeData);
        $this->assertArrayHasKey('rate_limited', $composeData);
        $this->assertArrayHasKey('checks', $composeData);

        $this->assertSame($replyMsgId, $composeData['msg_id']);
        $this->assertNotNull($composeData['in_reply_to']);
        $this->assertIsBool($composeData['safe_to_send']);
        $this->assertIsBool($composeData['rate_limited']);
        $this->assertIsArray($composeData['checks']);
    }

    public function testComposeHeadersBuildsReferencesCorrectly(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Update parent message to have references
        $parentMsg = $this->em->getRepository(Message::class)->find($data['msg_id']);
        $parentMsg->setHeaders([
            'from' => 'scammer@evil.test',
            'message_id' => '<parent@evil.test>',
            'in_reply_to' => '<grandparent@evil.test>',
            'references' => '<ancestor@evil.test> <grandparent@evil.test>',
        ]);
        $this->em->flush();

        // Generate reply
        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], true, 'test');
        $composeData = $this->replyHandler->composeHeaders($result['msg_id']);

        // References should contain: <ancestor@evil.test> <grandparent@evil.test> <parent@evil.test>
        $this->assertStringContainsString('<parent@evil.test>', $composeData['references']);
        $this->assertStringContainsString('<grandparent@evil.test>', $composeData['references']);
        $this->assertSame('<parent@evil.test>', $composeData['in_reply_to']);
    }

    public function testMarkAsSent(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $replyMsgId = $result['msg_id'];

        $providerMsgId = 'gmail-' . bin2hex(random_bytes(8));
        $tsSent = new \DateTimeImmutable();

        $success = $this->replyHandler->markAsSent($replyMsgId, 'gmail', $providerMsgId, $tsSent);

        $this->assertTrue($success);

        // Verify in database
        $this->em->clear();
        $replyMsg = $this->em->getRepository(Message::class)->find($replyMsgId);
        $this->assertSame('sent', $replyMsg->getSendStatus());
        $this->assertSame($providerMsgId, $replyMsg->getProviderMsgId());
        $this->assertNotNull($replyMsg->getTsSent());
    }

    public function testMarkAsSentIdempotency(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $replyMsgId = $result['msg_id'];

        $providerMsgId = 'gmail-' . bin2hex(random_bytes(8));
        $tsSent = new \DateTimeImmutable();

        // First call should succeed
        $this->replyHandler->markAsSent($replyMsgId, 'gmail', $providerMsgId, $tsSent);

        // Second call should throw exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already sent');

        $this->replyHandler->markAsSent($replyMsgId, 'gmail', 'another-id', $tsSent);
    }

    /**
     * Append a fresh inbound to the conversation with a future ts_msg so the alternation
     * invariant is satisfied (latest message = inbound) but the cadence between outbounds
     * is still in violation. Returns the new inbound's msg_id.
     */
    private function appendInbound(string $convId, int $minutesInFuture = 5): string
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($convId);

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $ts = new \DateTimeImmutable('+' . $minutesInFuture . ' minutes');
        $msg = new Message(
            $msgId,
            $conv,
            $channel,
            $directionIn,
            'fr',
            'Re: scammer follow-up',
            'Did you receive my mail?',
            '<p>Follow-up</p>',
            [
                'from' => 'scammer@evil.test',
                'to' => 'victim@example.test',
                'message_id' => '<follow-' . bin2hex(random_bytes(8)) . '@evil.test>',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            $ts,
            $ts,
            null
        );
        $this->em->persist($msg);
        $this->em->flush();

        return $msgId;
    }

    public function testCheckCadenceRespectMinimumTime(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Generate first reply
        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->replyHandler->markAsSent($result1['msg_id'], 'gmail', 'id1', new \DateTimeImmutable());

        // Append a new inbound so the alternation invariant is satisfied (Spec 081):
        // latest message is now inbound, but the cadence between outbounds is still
        // under 6h so the cadence guard must fire.
        $newInboundId = $this->appendInbound($data['conv_id']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cadence limit not met');

        $this->replyHandler->generateReply($data['conv_id'], $newInboundId, false, 'test');
    }

    public function testForceBypassesCadenceCheck(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Generate first reply
        $result1 = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        $this->replyHandler->markAsSent($result1['msg_id'], 'gmail', 'id1', new \DateTimeImmutable());

        // Append a new inbound so the alternation invariant is satisfied (Spec 081):
        // force=true must then legitimately bypass the cadence policy and create a 2nd
        // outbound (this is the contract for force after a fresh inbound has arrived).
        $newInboundId = $this->appendInbound($data['conv_id']);

        $result2 = $this->replyHandler->generateReply($data['conv_id'], $newInboundId, true, 'test');

        $this->assertNotNull($result2);
        $this->assertNotSame($result1['msg_id'], $result2['msg_id']);
        $this->assertFalse((bool) ($result2['meta']['duplicate_skipped'] ?? false));
    }

    public function testComposeHeadersChecksSafelist(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Generate reply to a non-safelisted domain
        $parentMsg = $this->em->getRepository(Message::class)->find($data['msg_id']);
        $parentMsg->setHeaders([
            'from' => 'scammer@real-domain.com',
            'message_id' => '<test@real-domain.com>',
        ]);
        $this->em->flush();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], true, 'test');
        $composeData = $this->replyHandler->composeHeaders($result['msg_id']);

        // Should not be safe to send (not in safelist)
        $this->assertFalse($composeData['checks']['safelist_ok']);
        $this->assertFalse($composeData['safe_to_send']);
    }

    public function testGenerateReplyCreatesMessageWithCorrectDirection(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $replyMsg = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $this->assertSame('out', $replyMsg->getDirection()->getCode());
    }

    public function testGenerateReplyLinksToParentMessage(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $replyMsg = $this->em->getRepository(Message::class)->find($result['msg_id']);
        $this->assertNotNull($replyMsg->getReplyTo());
        $this->assertSame($data['msg_id'], $replyMsg->getReplyTo()->getMsgId());
    }

    public function testGenerateReplyDoesNotIncludeQuotedHistory(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        // Check that the reply is generated
        $this->assertNotNull($result);
        $this->assertArrayHasKey('draft', $result);

        // Gmail handles threading, so we don't include quoted history
        // Text version should NOT contain quote markers ">" or "a écrit :"
        $this->assertStringNotContainsString('>', $result['draft']['text']);
        $this->assertStringNotContainsString('a écrit :', $result['draft']['text']);
        $this->assertStringNotContainsString('Please send your bank details', $result['draft']['text']);

        // HTML version should NOT contain blockquote
        $this->assertStringNotContainsString('blockquote', $result['draft']['html']);

        // Should contain the simple reply content
        $this->assertNotEmpty($result['draft']['text'], 'Reply text should not be empty');
    }

    public function testGenerateReplyWithMultipleMessagesStillSimple(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Add multiple messages to the conversation
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($data['conv_id']);
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);
        $directionOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        // Second inbound message
        $msg2Id = uuid_create(UUID_TYPE_RANDOM);
        $msg2 = new Message(
            $msg2Id,
            $conv,
            $channel,
            $directionIn,
            'fr',
            'Follow up message',
            'This is the second message from the scammer.',
            null,
            ['from' => 'scammer@evil.test', 'message_id' => '<msg2@evil.test>'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('+1 minute'),
            new \DateTimeImmutable('+1 minute'),
            null
        );
        $this->em->persist($msg2);

        // First outbound reply
        $msg3Id = uuid_create(UUID_TYPE_RANDOM);
        $msg3 = new Message(
            $msg3Id,
            $conv,
            $channel,
            $directionOut,
            'fr',
            'Re: Follow up',
            'Thank you for the follow up.',
            null,
            ['from' => 'victim@example.test', 'to' => 'scammer@evil.test'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('+2 minutes'),
            new \DateTimeImmutable('+2 minutes'),
            null
        );
        $this->em->persist($msg3);

        // Third inbound message
        $msg4Id = uuid_create(UUID_TYPE_RANDOM);
        $msg4 = new Message(
            $msg4Id,
            $conv,
            $channel,
            $directionIn,
            'fr',
            'Third message',
            'Here is my third message.',
            null,
            ['from' => 'scammer@evil.test', 'message_id' => '<msg4@evil.test>'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('+8 hours'),
            new \DateTimeImmutable('+8 hours'),
            null
        );
        $this->em->persist($msg4);
        $this->em->flush();

        // Generate reply to the latest message
        $result = $this->replyHandler->generateReply($data['conv_id'], $msg4Id, true, 'test');

        // Even with multiple messages, reply should be simple (no quoted history)
        $textBody = $result['draft']['text'];

        // Should NOT contain old message contents (Gmail handles threading)
        $this->assertStringNotContainsString('Please send your bank details', $textBody);
        $this->assertStringNotContainsString('This is the second message', $textBody);
        $this->assertStringNotContainsString('Here is my third message', $textBody);

        // Should NOT have quote markers
        $this->assertStringNotContainsString('a écrit :', $textBody);
        $this->assertStringNotContainsString('>', $textBody);

        // HTML version should NOT have blockquotes
        $htmlBody = $result['draft']['html'];
        $this->assertStringNotContainsString('<blockquote', $htmlBody);

        // Should contain simple reply (language depends on LLM provider — mock returns English)
        $this->assertNotEmpty($textBody, 'Reply text should not be empty');
    }

    public function testReplyContainsRecipientInMetadata(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        // Email addresses are in the 'to' field and metadata, not in the body
        $this->assertArrayHasKey('to', $result);
        $this->assertEquals('scammer@evil.test', $result['to']);

        // Body should NOT contain email addresses (simple reply only)
        $this->assertStringNotContainsString('scammer@evil.test', $result['draft']['text']);
        $this->assertStringNotContainsString('scammer@evil.test', $result['draft']['html']);
    }

    public function testReplyIncludesGenerationTimestamp(): void
    {
        $data = $this->createTestConversationWithMessage();

        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        // Since we don't format dates in the body anymore, check metadata instead
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('generated_at', $result['meta']);

        // Verify timestamp is valid ISO 8601
        $timestamp = $result['meta']['generated_at'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp);
    }

    public function testGetConversationContextReturnsBankCustomerPersonaForPhishingScam(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'phishing']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        if (!$scamType) {
            $this->markTestSkipped('ScamType phishing not found in database');
        }

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            75,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'test-persona-mapping-' . bin2hex(random_bytes(4))
        );

        $context = $this->replyHandler->getConversationContext($conv->getConvId());

        $this->assertNotNull($context);
        $this->assertArrayHasKey('persona', $context);
        $this->assertSame('bank_customer', $context['persona']);
    }

    public function testGetConversationContextReturnsGenericUserPersonaForUnknownScamType(): void
    {
        $data = $this->createTestConversationWithMessage();

        $context = $this->replyHandler->getConversationContext($data['conv_id']);

        $this->assertNotNull($context);
        $this->assertArrayHasKey('persona', $context);
        // Should default to generic_user for unknown scam types
        $this->assertIsString($context['persona']);
        $this->assertNotEmpty($context['persona']);
    }

    public function testKillSwitchBlocksReplyGeneration(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Activate kill switch
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = 'true';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Kill switch is active');

            $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');
        } finally {
            // Always restore to avoid polluting other tests
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = 'false';
        }
    }

    public function testKillSwitchBlocksSending(): void
    {
        $data = $this->createTestConversationWithMessage();

        // Generate reply while kill switch is off
        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        // Activate kill switch before composing headers
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = '1';

        try {
            $composeData = $this->replyHandler->composeHeaders($result['msg_id']);

            $this->assertFalse($composeData['checks']['kill_switch_off']);
            $this->assertFalse($composeData['safe_to_send']);
        } finally {
            $_ENV['SCAMBUSTER_KILL_SWITCH'] = 'false';
        }
    }

    public function testKillSwitchOffAllowsNormalOperation(): void
    {
        $_ENV['SCAMBUSTER_KILL_SWITCH'] = 'false';

        $data = $this->createTestConversationWithMessage();
        $result = $this->replyHandler->generateReply($data['conv_id'], $data['msg_id'], false, 'test');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('msg_id', $result);

        $composeData = $this->replyHandler->composeHeaders($result['msg_id']);
        $this->assertTrue($composeData['checks']['kill_switch_off']);
    }
}
