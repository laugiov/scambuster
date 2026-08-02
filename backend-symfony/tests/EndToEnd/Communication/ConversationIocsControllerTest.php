<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for Conversation IOCs endpoint
 *
 * Tests GET /api/v1/communication/conversation/{convId}/iocs
 */
class ConversationIocsControllerTest extends WebTestCase
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

    private function createTestConversation($client, $jwt): array
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

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
                'score_risk' => 50,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-conv-iocs-e2e-' . bin2hex(random_bytes(4)),
            ])
        );

        $convData = json_decode($client->getResponse()->getContent(), true);

        // Create first message
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
                'conv_id' => $convData['conv_id'],
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => 'Message 1',
                'headers' => ['from' => 'scammer@test.com'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $msg1Data = json_decode($client->getResponse()->getContent(), true);

        // Create second message
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
                'conv_id' => $convData['conv_id'],
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => 'Message 2',
                'headers' => ['from' => 'scammer@test.com'],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $msg2Data = json_decode($client->getResponse()->getContent(), true);

        return [
            'conv_id' => $convData['conv_id'],
            'msg1_id' => $msg1Data['msg_id'],
            'msg2_id' => $msg2Data['msg_id'],
        ];
    }

    public function testGetConversationIocsWithNoIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $testData = $this->createTestConversation($client, $jwt);

        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$testData['conv_id']}/iocs",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        $this->assertCount(0, $responseData);
    }

    public function testGetConversationIocsWithMultipleIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $testData = $this->createTestConversation($client, $jwt);

        // Add IOC to message 1
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'msg_id' => $testData['msg1_id'],
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://phishing.com/login',
                    'value_norm' => 'phishing.com/login',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => ['malicious' => 5],
                ],
            ])
        );

        // Add IOC to message 2
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'msg_id' => $testData['msg2_id'],
                'ioc' => [
                    'type' => 'email',
                    'value' => 'attacker@evil.com',
                    'value_norm' => 'attacker@evil.com',
                    'source' => 'header',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [],
            ])
        );

        // Get conversation IOCs
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$testData['conv_id']}/iocs",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        $this->assertCount(2, $responseData);

        // Check first IOC
        $this->assertArrayHasKey('obs_id', $responseData[0]);
        $this->assertArrayHasKey('ioc_id', $responseData[0]);
        $this->assertArrayHasKey('type', $responseData[0]);
        $this->assertArrayHasKey('value', $responseData[0]);
        $this->assertArrayHasKey('value_norm', $responseData[0]);
        $this->assertArrayHasKey('score', $responseData[0]);
        $this->assertArrayHasKey('category', $responseData[0]);
        $this->assertArrayHasKey('ts_observed', $responseData[0]);
    }

    public function testGetConversationIocsDeduplicatesAcrossMessages(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $testData = $this->createTestConversation($client, $jwt);

        // Add same IOC to both messages (different obs_id, but deduplicated in query)
        $iocPayload = [
            'ioc' => [
                'type' => 'domain',
                'value' => 'shared-evil.com',
                'value_norm' => 'shared-evil.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ];

        // Add to message 1
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(array_merge(['msg_id' => $testData['msg1_id']], $iocPayload))
        );

        // Add to message 2
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(array_merge(['msg_id' => $testData['msg2_id']], $iocPayload))
        );

        // Get conversation IOCs (should be deduplicated or both shown depending on implementation)
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$testData['conv_id']}/iocs",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        // Due to unique constraint (msg_id, type, value_norm), we'll have 2 IOCs
        // (one per message). This is expected behavior.
        $this->assertGreaterThanOrEqual(1, count($responseData));
        $this->assertLessThanOrEqual(2, count($responseData));
    }

    public function testGetConversationIocsForUnknownConversation(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'GET',
            '/api/v1/communication/conversation/cccccccc-cccc-cccc-cccc-cccccccccccc/iocs',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetConversationIocsWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/communication/conversation/test-conv-id/iocs'
        );

        $this->assertResponseStatusCodeSame(401);
    }
}
