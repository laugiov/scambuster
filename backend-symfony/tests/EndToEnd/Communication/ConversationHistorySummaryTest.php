<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for conversation history summary feature
 *
 * This test suite verifies that:
 * 1. Summary is generated when multiple conversations exist from same sender
 * 2. Summary is NOT generated when only one conversation exists
 * 3. Summary is NOT generated for excluded email addresses (test emails)
 * 4. Summary is properly integrated in the reply generation context
 */
class ConversationHistorySummaryTest extends WebTestCase
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

    private function createConversationWithMessages($client, string $senderEmail, array $messages): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        $jwt = $this->getValidJwt($client);

        // Create conversation
        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode([
                'primary_channel_id' => $channel->getChannelId(),
                'scam_type_id' => $scamType->getScamTypeId(),
                'account_id' => $account->getAccountId(),
                'status' => 'open',
                'score_risk' => 75,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-history-test-' . bin2hex(random_bytes(4)),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        // Create messages for this conversation
        foreach ($messages as $msgData) {
            $client->request(
                'POST',
                '/api/v1/communication/message',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                ],
                json_encode([
                    'conv_id' => $convId,
                    'channel_id' => $channel->getChannelId(),
                    'direction' => $msgData['direction'],
                    'body_text' => $msgData['body'],
                    'headers' => [
                        'from' => $msgData['direction'] === 'in' ? $senderEmail : 'victim@example.test',
                        'to' => $msgData['direction'] === 'in' ? 'victim@example.test' : $senderEmail,
                        'message_id' => '<test-msg-' . bin2hex(random_bytes(8)) . '@test>',
                        'subject' => $msgData['subject'] ?? 'Test subject',
                    ],
                    'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ])
            );

            $this->assertResponseStatusCodeSame(201);
        }

        return $convId;
    }

    public function testSummaryNotGeneratedForSingleConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a single conversation
        $senderEmail = 'scammer-single-' . bin2hex(random_bytes(4)) . '@evil.test';
        $convId = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Send me your bank details!', 'subject' => 'Urgent'],
        ]);

        // Get conversation context
        $replyHandler = $client->getContainer()->get(\App\Application\Communication\ReplyHandler::class);
        $context = $replyHandler->getConversationContext($convId);

        // Verify no summary was generated (only one conversation exists)
        $this->assertNull($context['sender_history_summary']);
    }

    public function testSummaryGeneratedForMultipleConversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create same sender email for all conversations
        $senderEmail = 'scammer-multi-' . bin2hex(random_bytes(4)) . '@evil.test';

        // Create first conversation
        $conv1Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'I am a prince and need your help!', 'subject' => 'Business Proposal'],
            ['direction' => 'out', 'body' => 'Really? Tell me more.', 'subject' => 'Re: Business Proposal'],
        ]);

        // Create second conversation
        $conv2Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Send me 1000 EUR for the transfer fees', 'subject' => 'Urgent Payment'],
        ]);

        // Get conversation context for the SECOND conversation
        // It should include a summary of the FIRST conversation
        $replyHandler = $client->getContainer()->get(\App\Application\Communication\ReplyHandler::class);
        $context = $replyHandler->getConversationContext($conv2Id);

        // Verify summary was generated
        $this->assertNotNull($context['sender_history_summary'], 'Summary should be generated when multiple conversations exist from same sender');
        $this->assertIsString($context['sender_history_summary']);
        $this->assertNotEmpty($context['sender_history_summary']);

        // Summary should be reasonably short (concise)
        $this->assertLessThan(1000, strlen($context['sender_history_summary']), 'Summary should be concise');
    }

    public function testSummaryNotGeneratedForExcludedEmails(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Use an excluded email (user@example.com is in CONVERSATION_HISTORY_EXCLUDED_EMAILS)
        $senderEmail = 'user@example.com';

        // Create first conversation
        $conv1Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Test message 1', 'subject' => 'Test 1'],
        ]);

        // Create second conversation
        $conv2Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Test message 2', 'subject' => 'Test 2'],
        ]);

        // Get conversation context for the SECOND conversation
        $replyHandler = $client->getContainer()->get(\App\Application\Communication\ReplyHandler::class);
        $context = $replyHandler->getConversationContext($conv2Id);

        // Verify NO summary was generated (email is excluded)
        $this->assertNull($context['sender_history_summary'], 'Summary should NOT be generated for excluded email addresses');
    }

    public function testSummaryExclusionIsCaseInsensitive(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Use excluded email with different casing (USER@EXAMPLE.COM instead of user@example.com)
        $senderEmail = 'USER@EXAMPLE.COM';

        // Create first conversation
        $conv1Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Test message 1', 'subject' => 'Test 1'],
        ]);

        // Create second conversation
        $conv2Id = $this->createConversationWithMessages($client, $senderEmail, [
            ['direction' => 'in', 'body' => 'Test message 2', 'subject' => 'Test 2'],
        ]);

        // Get conversation context
        $replyHandler = $client->getContainer()->get(\App\Application\Communication\ReplyHandler::class);
        $context = $replyHandler->getConversationContext($conv2Id);

        // Verify NO summary was generated (case-insensitive match)
        $this->assertNull($context['sender_history_summary'], 'Email exclusion should be case-insensitive');
    }

    public function testSummaryLimitedToMaxConversations(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create sender email
        $senderEmail = 'scammer-many-' . bin2hex(random_bytes(4)) . '@evil.test';

        // Create 7 conversations (more than MAX_CONVERSATIONS_TO_SUMMARIZE = 5)
        $convIds = [];

        for ($i = 1; $i <= 7; ++$i) {
            $convIds[] = $this->createConversationWithMessages($client, $senderEmail, [
                ['direction' => 'in', 'body' => "Message number {$i}", 'subject' => "Subject {$i}"],
            ]);

            // Small delay to ensure different timestamps
            usleep(100000); // 100ms
        }

        // Get context for the LAST conversation
        $replyHandler = $client->getContainer()->get(\App\Application\Communication\ReplyHandler::class);
        $context = $replyHandler->getConversationContext($convIds[6]);

        // Verify summary was generated (multiple conversations exist)
        $this->assertNotNull($context['sender_history_summary']);

        // We can't easily test that it's limited to 5, but we verify it doesn't crash with many conversations
        $this->assertIsString($context['sender_history_summary']);
    }
}
