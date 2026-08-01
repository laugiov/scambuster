<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

/**
 * Flow 6: Conversation lifecycle from birth to death.
 *
 * Verifies: ingest creates conversation (open), second email threads into same conversation,
 * close endpoint works, conversation status becomes closed, and reply generation fails on closed.
 */
final class ConversationLifecycleFlowTest extends AbstractCriticalFlowTestCase
{
    public function test_conversation_from_open_to_closed(): void
    {
        $client = static::createClient();
        $jwt = $this->getJwt($client);
        $uniqueSuffix = bin2hex(random_bytes(4));
        $messageId1 = '<lifecycle-1-' . $uniqueSuffix . '@evil.test>';

        // Step 1: Ingest first email -> creates conversation (open)
        $result1 = $this->ingestEmail(
            $client,
            $jwt,
            "scammer-lifecycle-{$uniqueSuffix}@evil.test",
            "Business proposal {$uniqueSuffix}",
            'I have a confidential business proposal for you. Please respond urgently.',
            null,
            $messageId1,
        );
        $convId = $result1['conv_id'];
        $msgId1 = $result1['msg_id'];

        // Step 2: Verify conversation is open
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$convId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $conv = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('open', $conv['status'], 'New conversation should be open');
        $this->assertSame($convId, $conv['conv_id']);

        // Step 3: Ingest a second email (reply to first) -> same conversation
        $result2 = $this->ingestEmail(
            $client,
            $jwt,
            "scammer-lifecycle-{$uniqueSuffix}@evil.test",
            "Re: Business proposal {$uniqueSuffix}",
            'Please hurry, the deadline is approaching fast. We need your cooperation.',
            $messageId1,
        );
        $convId2 = $result2['conv_id'];
        $msgId2 = $result2['msg_id'];
        $this->assertSame($convId, $convId2, 'Reply email should thread into the same conversation');
        $this->assertNotSame($msgId1, $msgId2, 'Second email should create a new message');

        // Step 4: Verify conversation still open with updated messages
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$convId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $conv2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('open', $conv2['status']);

        // Step 5: Close the conversation
        $client->request(
            'POST',
            "/api/v1/scambaiting/conversation/{$convId}/close",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $closeResult = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($closeResult['success'], 'Conversation closure should succeed');

        // Step 6: Verify conversation is now closed
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$convId}",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );
        $this->assertResponseIsSuccessful();
        $convClosed = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('closed', $convClosed['status'], 'Conversation should be closed after closure endpoint');

        // Step 7: Try to generate a reply on the closed conversation -> should fail
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
                'last_msg_id' => $msgId2,
                'force' => true,
                'reason' => 'lifecycle_test_after_close',
            ]),
        );
        $closedReplyStatus = $client->getResponse()->getStatusCode();
        $this->assertSame(400, $closedReplyStatus, 'Reply generation on closed conversation should return 400');
        $errorData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $errorData);
        $this->assertStringContainsString('closed', strtolower($errorData['error']), 'Error should mention conversation is closed');
    }
}
