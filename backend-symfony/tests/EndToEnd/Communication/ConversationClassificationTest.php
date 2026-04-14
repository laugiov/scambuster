<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E tests for Sprint 3 conversation classification endpoints
 */
class ConversationClassificationTest extends WebTestCase
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

    /**
     * Helper to create a test conversation with messages
     */
    private function createTestConversation($client, $jwt): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Get required entities
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy(['code' => 'UNKNOWN']);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel, 'Channel not found in fixtures');
        $this->assertNotNull($scamType, 'UNKNOWN scam type not found in fixtures');
        $this->assertNotNull($account, 'Mail account not found in fixtures');

        // Create conversation
        $payload = [
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 50,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix--' . bin2hex(random_bytes(16)),
        ];

        $client->request(
            'POST',
            '/api/v1/communication/conversation',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(201, 'Failed to create test conversation');
        $data = json_decode($client->getResponse()->getContent(), true);
        $convId = $data['conv_id'];

        // Refresh entity manager to get fresh conversation
        $em->clear();
        $conversation = $em->find(\App\Domain\Communication\Conversation::class, $convId);
        $channelForMsg = $em->find(\App\Domain\Communication\Channel::class, $channel->getChannelId());
        $directionForMsg = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy(['code' => 'in']);

        // Add a test message to the conversation (needed for LLM classification)
        $message = new \App\Domain\Communication\Message(
            msgId: \Ramsey\Uuid\Uuid::uuid4()->toString(),
            conversation: $conversation,
            channel: $channelForMsg,
            direction: $directionForMsg,
            langDetect: 'en',
            subject: 'Urgent: Verify your account',
            bodyText: 'Dear user, please click this link to verify your account or it will be suspended.',
            bodyHtml: '<p>Dear user, please click this link to verify your account or it will be suspended.</p>',
            headers: ['From' => 'scammer@evil.com', 'To' => 'victim@example.com'],
            compositeHash: hash('sha256', 'test-message-' . time() . '-' . bin2hex(random_bytes(8))),
            vectorId: null,
            replyTo: null,
            tsMsg: new \DateTimeImmutable(),
            tsIngest: new \DateTimeImmutable()
        );
        $em->persist($message);
        $em->flush();

        return $convId;
    }

    public function testManualClassifyConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a test conversation
        $convId = $this->createTestConversation($client, $jwt);

        // Get a valid scam type code for classification
        $em = $client->getContainer()->get('doctrine')->getManager();
        $phishingType = $em->getRepository(\App\Domain\Communication\ScamType::class)
            ->findOneBy(['code' => 'PHISHING']);

        $this->assertNotNull($phishingType, 'PHISHING scam type not found in fixtures');

        // Test: Classify conversation manually
        $payload = [
            'scam_type_code' => 'PHISHING',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        // Verify response structure
        $this->assertArrayHasKey('conv_id', $data);
        $this->assertArrayHasKey('scam_type_code', $data);
        $this->assertArrayHasKey('scam_type_label', $data);
        $this->assertArrayHasKey('persona_code', $data);
        $this->assertArrayHasKey('classified_at', $data);

        // Verify classification values
        $this->assertSame($convId, $data['conv_id']);
        $this->assertSame('PHISHING', $data['scam_type_code']);
        $this->assertNotEmpty($data['scam_type_label']);

        // Verify conversation was updated in database
        $em->clear();
        $conversation = $em->find(\App\Domain\Communication\Conversation::class, $convId);
        $this->assertNotNull($conversation);
        $this->assertSame('PHISHING', $conversation->getScamType()->getCode());
    }

    public function testManualClassifyConversationWithPersona(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a test conversation
        $convId = $this->createTestConversation($client, $jwt);

        // Get a valid persona code
        $em = $client->getContainer()->get('doctrine')->getManager();
        $persona = $em->getRepository(\App\Domain\Communication\Persona::class)->findOneBy([]);

        $this->assertNotNull($persona, 'No persona found in fixtures');

        // Test: Classify conversation manually with persona
        $payload = [
            'scam_type_code' => 'PHISHING',
            'persona_code' => $persona->getPersonaCode(),
        ];

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        // Verify persona was assigned
        $this->assertSame($persona->getPersonaCode(), $data['persona_code']);
        $this->assertNotNull($data['persona_label']);

        // Verify in database
        $em->clear();
        $conversation = $em->find(\App\Domain\Communication\Conversation::class, $convId);
        $this->assertNotNull($conversation->getPersona());
        $this->assertSame($persona->getPersonaCode(), $conversation->getPersona()->getPersonaCode());
    }

    public function testManualClassifyConversationNotFound(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Test: Try to classify non-existent conversation
        $fakeConvId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $payload = [
            'scam_type_code' => 'PHISHING',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $fakeConvId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }

    public function testManualClassifyConversationInvalidScamType(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a test conversation
        $convId = $this->createTestConversation($client, $jwt);

        // Test: Try to classify with invalid scam type
        $payload = [
            'scam_type_code' => 'INVALID_SCAM_TYPE_XYZ',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }

    public function testManualClassifyConversationMissingScamTypeCode(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a test conversation
        $convId = $this->createTestConversation($client, $jwt);

        // Test: Try to classify without scam_type_code
        $payload = [];

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('scam_type_code is required', $data['error']);
    }

    public function testAutoClassifyConversationAlreadyClassified(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create a test conversation already classified as PHISHING
        $convId = $this->createTestConversation($client, $jwt);

        // First, manually classify it
        $payload = ['scam_type_code' => 'PHISHING'];
        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode($payload)
        );
        $this->assertResponseStatusCodeSame(200);

        // Test: Auto-classify should skip (already classified)
        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $convId . '/auto-classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['force' => false])
        );

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        // Should return existing classification
        $this->assertArrayHasKey('conv_id', $data);
        $this->assertArrayHasKey('scam_type_code', $data);
        $this->assertArrayHasKey('confidence', $data);
        $this->assertArrayHasKey('is_new_scam_type', $data);
        $this->assertArrayHasKey('is_new_persona', $data);

        $this->assertSame('PHISHING', $data['scam_type_code']);
        $this->assertEquals(1.0, $data['confidence']); // Already classified = 100% confidence (allow int/float comparison)
        $this->assertFalse($data['is_new_scam_type']);
        $this->assertFalse($data['is_new_persona']);
    }

    public function testAutoClassifyConversationNotFound(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Test: Try to auto-classify non-existent conversation
        $fakeConvId = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $client->request(
            'POST',
            '/api/v1/communication/conversation/' . $fakeConvId . '/auto-classify',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['force' => true])
        );

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }
}
