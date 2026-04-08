<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * E2E tests for Sprint 3 IOC extraction endpoint
 */
class MessageIocExtractionTest extends WebTestCase
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
     * Helper to create a test message with IOCs
     */
    private function createTestMessageWithIocs($client, $jwt): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Get required entities
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy(['code' => 'UNKNOWN']);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        // Create conversation first
        $conversation = new \App\Domain\Communication\Conversation(
            \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            $channel,
            $scamType,
            $account,
            \App\Domain\Communication\ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix--' . bin2hex(random_bytes(16))
        );
        $em->persist($conversation);
        $em->flush();

        $direction = $em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy(['code' => 'in']);

        // Create message with IOCs in subject and body
        $message = new \App\Domain\Communication\Message(
            msgId: \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            conversation: $conversation,
            channel: $channel,
            direction: $direction,
            langDetect: 'en',
            subject: 'Urgent: Contact us at support@evil-phishing.com',
            bodyText: 'Dear user, please visit http://malicious-site.com and contact us at 192.0.2.1 or scammer@badguy.net for assistance. Hash: d41d8cd98f00b204e9800998ecf8427e',
            bodyHtml: '<p>Dear user, please visit <a href="http://malicious-site.com">our site</a></p>',
            headers: ['From' => 'scammer@evil.com', 'To' => 'victim@example.com'],
            compositeHash: hash('sha256', 'test-message-ioc-' . time() . '-' . bin2hex(random_bytes(8))),
            vectorId: null,
            replyTo: null,
            tsMsg: new \DateTimeImmutable(),
            tsIngest: new \DateTimeImmutable()
        );
        $em->persist($message);
        $em->flush();

        return $message->getMsgId();
    }

    public function testExtractIocsWithRegexMethod(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message with IOCs
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract IOCs using regex method
        $payload = [
            'method' => 'regex',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertArrayHasKey('method', $data);
        $this->assertArrayHasKey('iocs_found', $data);
        $this->assertArrayHasKey('iocs', $data);
        $this->assertArrayHasKey('extraction_time_ms', $data);

        // Verify values
        $this->assertSame($msgId, $data['msg_id']);
        $this->assertSame('regex', $data['method']);
        $this->assertGreaterThan(0, $data['iocs_found'], 'Should find at least some IOCs');
        $this->assertIsArray($data['iocs']);

        // Verify IOC structure
        if (count($data['iocs']) > 0) {
            $ioc = $data['iocs'][0];
            $this->assertArrayHasKey('type', $ioc);
            $this->assertArrayHasKey('value', $ioc);
            $this->assertArrayHasKey('value_norm', $ioc);
            $this->assertArrayHasKey('context', $ioc);
        }

        // Verify some expected IOCs are found (at least email or url)
        $types = array_column($data['iocs'], 'type');
        $this->assertTrue(
            in_array('email', $types) || in_array('url', $types) || in_array('md5', $types),
            'Should find at least email, url, or md5 IOCs'
        );
    }

    public function testExtractIocsWithSpecificTypes(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract only email IOCs
        $payload = [
            'method' => 'regex',
            'types' => ['email'],
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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

        // Verify only email IOCs are returned
        foreach ($data['iocs'] as $ioc) {
            $this->assertSame('email', $ioc['type'], 'Should only return email IOCs when types filter is used');
        }

        // Should find at least 2 emails (support@evil-phishing.com, scammer@badguy.net)
        $this->assertGreaterThanOrEqual(2, $data['iocs_found'], 'Should find at least 2 email IOCs');
    }

    public function testExtractIocsWithHybridMethod(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract IOCs using hybrid method (regex + LLM, but LLM not implemented yet)
        $payload = [
            'method' => 'hybrid',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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

        $this->assertSame('hybrid', $data['method']);
        $this->assertGreaterThan(0, $data['iocs_found']);
    }

    public function testExtractIocsWithDefaultMethod(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract IOCs without specifying method (should default to hybrid)
        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([])
        );

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('hybrid', $data['method'], 'Should default to hybrid method');
    }

    public function testExtractIocsMessageNotFound(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Test: Try to extract IOCs from non-existent message
        $fakeMsgId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $fakeMsgId . '/extract-iocs',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['method' => 'regex'])
        );

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }

    public function testExtractIocsInvalidMethod(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Try to extract IOCs with invalid method
        $payload = [
            'method' => 'invalid_method',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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
        $this->assertStringContainsString('Invalid method', $data['error']);
    }

    /**
     * Sprint 3 - Test LLM extraction method
     */
    public function testExtractIocsWithLLMMethod(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message with IOCs
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract IOCs using LLM method
        $payload = [
            'method' => 'llm',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertArrayHasKey('method', $data);
        $this->assertArrayHasKey('iocs_found', $data);
        $this->assertArrayHasKey('iocs', $data);
        $this->assertArrayHasKey('extraction_time_ms', $data);

        // Verify values
        $this->assertSame($msgId, $data['msg_id']);
        $this->assertSame('llm', $data['method']);
        $this->assertIsArray($data['iocs']);

        // Verify all IOCs have extraction_method = 'llm'
        foreach ($data['iocs'] as $ioc) {
            $this->assertArrayHasKey('context', $ioc);
            $this->assertArrayHasKey('extraction_method', $ioc['context']);
            $this->assertSame('llm', $ioc['context']['extraction_method']);
        }
    }

    public function testExtractIocsWithLLMMethodAndTypeFilter(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract only email IOCs using LLM
        $payload = [
            'method' => 'llm',
            'types' => ['email'],
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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

        // Verify only email IOCs are returned
        foreach ($data['iocs'] as $ioc) {
            $this->assertSame('email', $ioc['type'], 'Should only return email IOCs when types filter is used with LLM');
        }
    }

    public function testExtractIocsWithHybridMethodCombinesRegexAndLLM(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract IOCs using hybrid method (should combine regex + LLM)
        $payload = [
            'method' => 'hybrid',
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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

        $this->assertSame('hybrid', $data['method']);

        // Verify IOCs have extraction_method marked (should be 'regex' or 'llm')
        if (count($data['iocs']) > 0) {
            foreach ($data['iocs'] as $ioc) {
                $this->assertArrayHasKey('extraction_method', $ioc['context']);
                $this->assertContains(
                    $ioc['context']['extraction_method'],
                    ['regex', 'llm'],
                    'Hybrid method should mark IOCs as either regex or llm'
                );
            }
        }
    }

    public function testExtractIocsWithLLMMethodDefangsURLs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create test message
        $msgId = $this->createTestMessageWithIocs($client, $jwt);

        // Test: Extract URLs and verify defanging
        $payload = [
            'method' => 'llm',
            'types' => ['url'],
        ];

        $client->request(
            'POST',
            '/api/v1/communication/message/' . $msgId . '/extract-iocs',
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

        // Verify URLs are defanged in value_norm
        foreach ($data['iocs'] as $ioc) {
            if ($ioc['type'] === 'url') {
                // value_norm should have hxxp/hxxps and [.]
                $this->assertStringContainsString(
                    'hxxp',
                    $ioc['value_norm'],
                    'URLs should be defanged with hxxp/hxxps in value_norm'
                );
            }
        }
    }
}
