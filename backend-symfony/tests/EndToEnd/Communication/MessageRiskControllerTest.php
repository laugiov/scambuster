<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for Message Risk endpoint
 *
 * Tests GET /api/v1/communication/message/{msgId}/risk
 */
class MessageRiskControllerTest extends WebTestCase
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

    private function createTestMessage($client, $jwt): string
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
                'stix_id' => 'stix-risk-e2e-' . bin2hex(random_bytes(4)),
            ])
        );

        $convData = json_decode($client->getResponse()->getContent(), true);

        // Create message
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
                'body_text' => 'Test message',
                'headers' => [
                    'from' => 'scammer@test.com',
                    'to' => 'victim@test.com',
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $msgData = json_decode($client->getResponse()->getContent(), true);
        return $msgData['msg_id'];
    }

    public function testGetMessageRiskWithNoIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        $client->request(
            'GET',
            "/api/v1/communication/message/$msgId/risk",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('score_agg', $responseData);
        $this->assertArrayHasKey('level', $responseData);
        $this->assertArrayHasKey('reason', $responseData);
        $this->assertArrayHasKey('should_reply', $responseData);

        $this->assertSame(0, $responseData['score_agg']);
        $this->assertSame('low', $responseData['level']);
        $this->assertFalse($responseData['should_reply']);
        $this->assertSame('No IOCs detected', $responseData['reason']);
    }

    public function testGetMessageRiskWithHighRiskIoc(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        // Add high-risk IOC
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
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://very-dangerous-malware.com/exploit',
                    'value_norm' => 'very-dangerous-malware.com/exploit',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => ['malicious' => 50],
                    'urlscan' => ['verdict' => 'malicious'],
                ],
            ])
        );

        // Get risk
        $client->request(
            'GET',
            "/api/v1/communication/message/$msgId/risk",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(100, $responseData['score_agg']);
        $this->assertSame('high', $responseData['level']);
        $this->assertTrue($responseData['should_reply']);
    }

    public function testGetMessageRiskWithMediumRiskAndExploitableIoc(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        // Add medium-risk URL
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
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://suspicious.com',
                    'value_norm' => 'suspicious.com',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => ['suspicious' => 2],
                ],
            ])
        );

        // Add IBAN (exploitable)
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
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'iban',
                    'value' => 'FR7630006000011234567890189',
                    'value_norm' => 'FR7630006000011234567890189',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [],
            ])
        );

        // Get risk
        $client->request(
            'GET',
            "/api/v1/communication/message/$msgId/risk",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame(40, $responseData['score_agg']);
        $this->assertSame('medium', $responseData['level']);
        $this->assertTrue($responseData['should_reply'], 'Should reply because IBAN is exploitable');
    }

    public function testGetMessageRiskForUnknownMessage(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'GET',
            '/api/v1/communication/message/dddddddd-dddd-dddd-dddd-dddddddddddd/risk',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetMessageRiskWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'GET',
            '/api/v1/communication/message/test-msg-id/risk'
        );

        $this->assertResponseStatusCodeSame(401);
    }
}
