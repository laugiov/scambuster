<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for IocController
 *
 * Tests POST /api/v1/iocs/enriched endpoint with real HTTP requests
 * and database persistence.
 */
class IocControllerTest extends WebTestCase
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
                'score_risk' => 50,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-ioc-e2e-' . bin2hex(random_bytes(4)),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

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
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => 'Click here: https://evil-phishing-site.com/login for urgent verification!',
                'headers' => [
                    'from' => 'scammer@phish.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<e2e-test-' . bin2hex(random_bytes(8)) . '@phish.test>',
                    'subject' => 'Urgent Account Verification',
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);
        $msgId = $msgData['msg_id'];

        // Set external_message_id manually (simulating what the ingest workflow would do)
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->find($msgId);
        $message->setExternalMessageId('<e2e-gmail-' . bin2hex(random_bytes(8)) . '@mail.gmail.com>');
        $em->flush();

        return $msgId;
    }

    public function testIngestEnrichedIocWithExternalMessageId(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        // Get the external_message_id from the created message
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->clear(); // Clear to force fresh fetch
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->find($msgId);
        $externalMessageId = $message->getExternalMessageId();

        $this->assertNotNull($externalMessageId, 'External message ID should be set');

        // Ingest enriched IOC using external_message_id
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
                'message_id' => $externalMessageId,
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://evil-phishing-site.com/login',
                    'value_norm' => 'evil-phishing-site.com/login',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => [
                        'malicious' => 8,
                        'suspicious' => 0,
                        'harmless' => 80,
                        'undetected' => 12,
                    ],
                    'urlscan' => [
                        'verdict' => 'malicious',
                        'status' => 'completed',
                        'permalink' => 'https://urlscan.io/result/abc123/',
                    ],
                ],
                'tags' => ['phishing', 'credential-theft'],
                'tlp' => 'AMBER',
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('obs_id', $responseData);
        $this->assertArrayHasKey('risk', $responseData);
        $this->assertSame(100, $responseData['risk']['score_agg']); // VT+URLscan malicious = capped at 100
        $this->assertSame('high', $responseData['risk']['level']);
        $this->assertTrue($responseData['risk']['should_reply']);

        // Verify MISP/STIX metadata is present in database
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $ioc = $em->getRepository(\App\Domain\Communication\ObservedIoc::class)->find($responseData['obs_id']);
        $this->assertNotNull($ioc);
        $context = $ioc->getContext();

        $this->assertArrayHasKey('misp', $context);
        $this->assertSame('Network activity', $context['misp']['category']);
        $this->assertSame('url', $context['misp']['type']);
        $this->assertTrue($context['misp']['to_ids']);

        $this->assertArrayHasKey('stix', $context);
        $this->assertSame('url', $context['stix']['sco_type']);
        $this->assertStringContainsString('[url:value =', $context['stix']['pattern']);
    }

    public function testIngestEnrichedIocWithInternalMsgId(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        // Ingest enriched IOC using internal msg_id
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
                    'type' => 'domain',
                    'value' => 'evil-phishing-site.com',
                    'value_norm' => 'evil-phishing-site.com',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => [
                        'malicious' => 0,
                        'suspicious' => 3,
                    ],
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('obs_id', $responseData);
        $this->assertSame(40, $responseData['risk']['score_agg']); // VT suspicious
        $this->assertSame('medium', $responseData['risk']['level']);
    }

    public function testIngestEnrichedIocIsIdempotent(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

        $payload = json_encode([
            'msg_id' => $msgId,
            'ioc' => [
                'type' => 'url',
                'value' => 'https://test-idempotence.com',
                'value_norm' => 'test-idempotence.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        // First request
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(201);
        $response1 = json_decode($client->getResponse()->getContent(), true);
        $obsId1 = $response1['obs_id'];

        // Second request (same payload)
        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(201);
        $response2 = json_decode($client->getResponse()->getContent(), true);
        $obsId2 = $response2['obs_id'];

        $this->assertSame($obsId1, $obsId2, 'Should return same IOC ID (idempotent)');
    }

    public function testIngestEnrichedIocWithMissingIocField(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

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
                // Missing 'ioc' field
                'enrichment' => [],
            ])
        );

        $this->assertResponseStatusCodeSame(400);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('ioc', $responseData['error']);
    }

    public function testIngestEnrichedIocWithInvalidIocType(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $msgId = $this->createTestMessage($client, $jwt);

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
                    'type' => 'invalid_type', // Invalid type
                    'value' => 'test',
                    'value_norm' => 'test',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(400);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Invalid IOC type', $responseData['error']);
    }

    public function testIngestEnrichedIocWithUnknownMessage(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

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
                'message_id' => '<nonexistent@nowhere.test>',
                'msg_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://test.com',
                    'value_norm' => 'test.com',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(404);
        $responseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Message not found', $responseData['error']);
    }

    public function testIngestEnrichedIocWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/iocs/enriched',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'msg_id' => 'test',
                'ioc' => [
                    'type' => 'url',
                    'value' => 'test',
                    'value_norm' => 'test',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
            ])
        );

        $this->assertResponseStatusCodeSame(401);
    }
}
