<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReplyControllerTest extends WebTestCase
{
    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    private function createTestConversationWithMessage($client, $jwt): array
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        // Create conversation
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'primary_channel_id' => $channel->getChannelId(),
                'scam_type_id' => $scamType->getScamTypeId(),
                'account_id' => $account->getAccountId(),
                'status' => 'open',
                'score_risk' => 75,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-reply-test-' . bin2hex(random_bytes(4)),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        // Create inbound message from "scammer"
        $client->request(
            'POST',
            '/api/v1/communication/message',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => 'Hello, I need you to send me your bank account details urgently!',
                'headers' => [
                    'from' => 'scammer@evil.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<test-msg-' . bin2hex(random_bytes(8)) . '@evil.test>',
                    'subject' => 'Urgent: Bank Account Verification',
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return [
            'conv_id' => $convId,
            'msg_id' => $msgData['msg_id'],
            'channel_id' => $channel->getChannelId(),
        ];
    }

    public function testGetConversationContext(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);
        $convId = $testData['conv_id'];

        // Get conversation context
        $client->request(
            'GET',
            "/api/v1/communication/conversation/$convId/context",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $context = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('conv_id', $context);
        $this->assertArrayHasKey('status', $context);
        $this->assertArrayHasKey('scam_type', $context);
        $this->assertArrayHasKey('persona', $context);
        $this->assertArrayHasKey('cadence', $context);
        $this->assertArrayHasKey('last_messages', $context);

        $this->assertSame($convId, $context['conv_id']);
        $this->assertSame('open', $context['status']);
        $this->assertIsArray($context['last_messages']);
        $this->assertGreaterThan(0, count($context['last_messages']));
    }

    public function testGenerateReply(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test_generation'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $reply = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('msg_id', $reply);
        $this->assertArrayHasKey('conv_id', $reply);
        $this->assertArrayHasKey('to', $reply);
        $this->assertArrayHasKey('subject', $reply);
        $this->assertArrayHasKey('draft', $reply);
        $this->assertArrayHasKey('meta', $reply);

        $this->assertSame($testData['conv_id'], $reply['conv_id']);
        $this->assertStringStartsWith('Re:', $reply['subject']);
        $this->assertIsArray($reply['draft']);
        $this->assertArrayHasKey('text', $reply['draft']);
        $this->assertArrayHasKey('html', $reply['draft']);
    }

    public function testGetReply(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply first
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $replyData = json_decode($client->getResponse()->getContent(), true);
        $replyMsgId = $replyData['msg_id'];

        // Get the reply
        $client->request(
            'GET',
            "/api/v1/communication/reply/$replyMsgId",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $reply = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('msg_id', $reply);
        $this->assertArrayHasKey('send_status', $reply);
        $this->assertArrayHasKey('to', $reply);
        $this->assertArrayHasKey('subject', $reply);
        $this->assertArrayHasKey('draft', $reply);

        $this->assertSame($replyMsgId, $reply['msg_id']);
        $this->assertSame('draft', $reply['send_status']);
    }

    public function testComposeReply(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply first
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $replyData = json_decode($client->getResponse()->getContent(), true);
        $replyMsgId = $replyData['msg_id'];

        // Get compose headers
        $client->request(
            'GET',
            "/api/v1/communication/reply/$replyMsgId/compose",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $compose = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('msg_id', $compose);
        $this->assertArrayHasKey('to', $compose);
        $this->assertArrayHasKey('from', $compose);
        $this->assertArrayHasKey('subject', $compose);
        $this->assertArrayHasKey('in_reply_to', $compose);
        $this->assertArrayHasKey('references', $compose);
        $this->assertArrayHasKey('safe_to_send', $compose);
        $this->assertArrayHasKey('rate_limited', $compose);
        $this->assertArrayHasKey('checks', $compose);

        $this->assertSame($replyMsgId, $compose['msg_id']);
        $this->assertIsBool($compose['safe_to_send']);
        $this->assertIsBool($compose['rate_limited']);
        $this->assertIsArray($compose['checks']);
    }

    public function testMarkReplySent(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply first
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $replyData = json_decode($client->getResponse()->getContent(), true);
        $replyMsgId = $replyData['msg_id'];

        // Mark as sent
        $client->request(
            'POST',
            "/api/v1/communication/reply/$replyMsgId/sent",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'provider' => 'gmail',
                'provider_msg_id' => 'test-gmail-id-' . bin2hex(random_bytes(8)),
                'ts_sent' => (new \DateTimeImmutable())->format(DATE_ATOM)
            ])
        );

        $this->assertResponseStatusCodeSame(204);

        // Verify status changed to 'sent'
        $client->request(
            'GET',
            "/api/v1/communication/reply/$replyMsgId",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $reply = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('sent', $reply['send_status']);
    }

    public function testMarkReplySentIdempotency(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $replyData = json_decode($client->getResponse()->getContent(), true);
        $replyMsgId = $replyData['msg_id'];

        // Mark as sent first time
        $sentPayload = [
            'provider' => 'gmail',
            'provider_msg_id' => 'test-gmail-id-' . bin2hex(random_bytes(8)),
            'ts_sent' => (new \DateTimeImmutable())->format(DATE_ATOM)
        ];

        $client->request(
            'POST',
            "/api/v1/communication/reply/$replyMsgId/sent",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($sentPayload)
        );

        $this->assertResponseStatusCodeSame(204);

        // A 2nd /sent with the SAME provider_msg_id is a
        // silent no-op (204), not a 400. This is the deliberate behaviour
        // change: stale n8n executions retrying /sent must not
        // surface as red errors on the operator dashboard.
        $client->request(
            'POST',
            "/api/v1/communication/reply/$replyMsgId/sent",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($sentPayload)
        );

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGenerateReplyForClosedConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Close the conversation
        $client->request(
            'PATCH',
            "/api/v1/communication/conversation/{$testData['conv_id']}",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['status' => 'closed'])
        );

        $this->assertResponseIsSuccessful();

        // Try to generate reply (should fail)
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(400);
        $error = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $error);
        $this->assertStringContainsString('closed', $error['error']);
    }

    public function testGenerateReplyDoesNotIncludeQuotedConversationHistory(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);

        // Generate reply
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $testData['conv_id'],
                'last_msg_id' => $testData['msg_id'],
                'force' => false,
                'reason' => 'test'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $reply = json_decode($client->getResponse()->getContent(), true);

        // Verify that the reply is generated
        $this->assertArrayHasKey('draft', $reply);

        // Gmail handles threading, so we don't include quoted history
        // Text version should NOT contain quote markers ">" or "a écrit :"
        $this->assertStringNotContainsString('>', $reply['draft']['text']);
        $this->assertStringNotContainsString('a écrit :', $reply['draft']['text']);
        $this->assertStringNotContainsString('bank account details', $reply['draft']['text']);

        // HTML version should NOT contain blockquote
        $this->assertStringNotContainsString('blockquote', $reply['draft']['html']);

        // Should contain the simple reply content
        $this->assertStringContainsString('Merci pour votre message', $reply['draft']['text']);
    }

    public function testGenerateReplyWithMultipleMessagesStillSimple(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $testData = $this->createTestConversationWithMessage($client, $jwt);
        $convId = $testData['conv_id'];
        $channelId = $testData['channel_id'];

        // Add a second inbound message
        $client->request(
            'POST',
            '/api/v1/communication/message',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $convId,
                'channel_id' => $channelId,
                'direction' => 'in',
                'body_text' => 'This is my follow-up message asking again for your information.',
                'headers' => [
                    'from' => 'scammer@evil.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<test-msg2-' . bin2hex(random_bytes(8)) . '@evil.test>',
                    'subject' => 'Re: Urgent: Bank Account Verification',
                ],
                'ts_msg' => (new \DateTimeImmutable('+10 seconds'))->format(DATE_ATOM)
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msg2Data = json_decode($client->getResponse()->getContent(), true);

        // Add a third inbound message
        $client->request(
            'POST',
            '/api/v1/communication/message',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $convId,
                'channel_id' => $channelId,
                'direction' => 'in',
                'body_text' => 'Final reminder: please respond immediately!',
                'headers' => [
                    'from' => 'scammer@evil.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<test-msg3-' . bin2hex(random_bytes(8)) . '@evil.test>',
                    'subject' => 'Re: Re: Urgent: Bank Account Verification',
                ],
                'ts_msg' => (new \DateTimeImmutable('+8 hours'))->format(DATE_ATOM)
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msg3Data = json_decode($client->getResponse()->getContent(), true);

        // Generate reply to the latest message
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'conv_id' => $convId,
                'last_msg_id' => $msg3Data['msg_id'],
                'force' => true,
                'reason' => 'test_multi_message'
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $reply = json_decode($client->getResponse()->getContent(), true);

        // Even with multiple messages, reply should be simple (no quoted history)
        $textBody = $reply['draft']['text'];

        // Should NOT contain old message contents (Gmail handles threading)
        $this->assertStringNotContainsString('bank account details urgently', $textBody);
        $this->assertStringNotContainsString('follow-up message', $textBody);
        $this->assertStringNotContainsString('Final reminder', $textBody);

        // Should NOT have quote markers
        $this->assertStringNotContainsString('a écrit :', $textBody);
        $this->assertStringNotContainsString('>', $textBody);

        // HTML version should NOT have blockquotes
        $htmlBody = $reply['draft']['html'];
        $this->assertStringNotContainsString('<blockquote', $htmlBody);

        // Should contain simple reply
        $this->assertStringContainsString('Merci pour votre message', $textBody);
    }
}
