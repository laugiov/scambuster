<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

/**
 * Flow 2: Honeypot reply loop — Email -> Context -> Reply generation -> Compose.
 *
 * Verifies: ingest, conversation context (scam_type, persona), reply generation,
 * reply persistence, and compose headers.
 */
final class EmailToReplyFlowTest extends AbstractCriticalFlowTestCase
{
    public function test_complete_email_to_reply_flow(): void
    {
        $client = static::createClient();
        $jwt = $this->getJwt($client);

        // Step 1: Ingest a scam email
        $ingestResult = $this->ingestEmail(
            $client,
            $jwt,
            'scammer-reply@evil.test',
            'You have won a million dollars!',
            'Congratulations! You have been selected as the winner of our lottery. Please send us your bank details to claim your prize.',
        );

        $msgId = $ingestResult['msg_id'];
        $convId = $ingestResult['conv_id'];

        // Step 2: Get conversation context and verify business fields
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$convId}/context",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );

        $this->assertResponseIsSuccessful();
        $context = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('conv_id', $context);
        $this->assertArrayHasKey('status', $context);
        $this->assertArrayHasKey('scam_type', $context);
        $this->assertArrayHasKey('persona', $context);
        $this->assertSame($convId, $context['conv_id']);
        $this->assertSame('open', $context['status']);

        // Step 3: Generate reply
        $client->request(
            'POST',
            '/api/v1/communication/reply/generate',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'conv_id' => $convId,
                'last_msg_id' => $msgId,
                'force' => true,
                'reason' => 'critical_flow_test',
            ]),
        );

        $this->assertResponseStatusCodeSame(201, 'Reply generation should return 201');
        $reply = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $reply);
        $this->assertArrayHasKey('draft', $reply);
        $this->assertArrayHasKey('text', $reply['draft']);
        $this->assertNotEmpty($reply['draft']['text'], 'Draft text must not be empty');
        $replyMsgId = $reply['msg_id'];

        // Step 4: Verify reply is persisted
        $client->request(
            'GET',
            "/api/v1/communication/reply/{$replyMsgId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );

        $this->assertResponseIsSuccessful();
        $persistedReply = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($replyMsgId, $persistedReply['msg_id']);
        $this->assertSame('draft', $persistedReply['send_status']);
        $this->assertArrayHasKey('draft', $persistedReply);

        // Step 5: Verify compose headers
        $client->request(
            'GET',
            "/api/v1/communication/reply/{$replyMsgId}/compose",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );

        $this->assertResponseIsSuccessful();
        $compose = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('to', $compose);
        $this->assertArrayHasKey('subject', $compose);
        $this->assertArrayHasKey('safe_to_send', $compose);
        $this->assertStringStartsWith('Re:', $compose['subject']);
        $this->assertIsBool($compose['safe_to_send']);
    }
}
