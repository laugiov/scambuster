<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end test for Campaign Radar Phase 3: LLM Services
 *
 * @group endtoend
 *
 * Scenario: Complete LLM workflow (Profile → Compile → Store)
 * 1. Create campaign with 3+ messages via clustering
 * 2. Profile campaign → verify YAML structure
 * 3. Get campaign messages → verify retrieval
 * 4. Compile rules from profile → verify DSL output
 * 5. Verify profile_yaml is stored in campaign
 */
class LlmWorkflowE2eTest extends WebTestCase
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

    private function createTestMessage($client, string $subject, string $body): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel, 'Channel fixture not found');
        $this->assertNotNull($scamType, 'ScamType fixture not found');
        $this->assertNotNull($account, 'MailAccount fixture not found');

        // Create conversation
        $jwt = $this->getValidJwt($client);
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
                'score_risk' => 80,
                'ts_first' => (new \DateTimeImmutable('-2 hours'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-llm-e2e-' . bin2hex(random_bytes(4)),
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
                'subject' => $subject,
                'body_text' => $body,
                'headers' => [
                    'from' => 'phishing@scam.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<llm-e2e-' . bin2hex(random_bytes(8)) . '@scam.test>',
                    'auth' => ['dkim' => false, 'spf' => false],
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return $msgData['msg_id'];
    }

    private function createCampaignWithMessages($client): string
    {
        // Cleanup
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->getConnection()->executeStatement('DELETE FROM message_campaign WHERE campaign_id IN (SELECT campaign_id FROM campaign WHERE created_by = \'llm-e2e-test\')');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'llm-e2e-test\'');
        $em->clear();

        // Create 3 similar messages for bank phishing campaign
        $msgId1 = $this->createTestMessage(
            $client,
            'Urgent: Account Security Alert',
            'Your bank account has been temporarily locked due to suspicious activity. Click here to verify: https://secure-bank.scam.test/verify'
        );

        $msgId2 = $this->createTestMessage(
            $client,
            'Urgent: Account Security Alert',
            'Your bank account has been temporarily locked due to suspicious activity. Click here to confirm: https://secure-bank.scam.test/confirm'
        );

        $msgId3 = $this->createTestMessage(
            $client,
            'Urgent: Account Security Alert',
            'Your bank account has been temporarily locked due to suspicious activity. Click here to restore: https://secure-bank.scam.test/restore'
        );

        $jwt = $this->getValidJwt($client);

        // Cluster messages to create campaign
        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['msg_id' => $msgId1])
        );

        $this->assertResponseStatusCodeSame(201);
        $response1 = json_decode($client->getResponse()->getContent(), true);
        $campaignId = $response1['campaign_id'];

        // Tag campaign for cleanup
        $campaign = $em->find(\App\Domain\CampaignRadar\Campaign::class, $campaignId);
        if ($campaign) {
            // Use reflection to update createdBy for cleanup
            $reflection = new \ReflectionClass($campaign);
            $property = $reflection->getProperty('createdBy');
            $property->setAccessible(true);
            $property->setValue($campaign, 'llm-e2e-test');
            $em->flush();
        }

        // Assign other messages
        foreach ([$msgId2, $msgId3] as $msgId) {
            $client->request(
                'POST',
                '/api/v1/campaign/cluster/assign',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
                ],
                json_encode(['msg_id' => $msgId])
            );
        }

        return $campaignId;
    }

    /**
     * @group endtoend
     */
    public function testGetCampaignMessages(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client);
        $jwt = $this->getValidJwt($client);

        // Get campaign messages
        $client->request(
            'GET',
            "/api/v1/campaign/{$campaignId}/messages?limit=10",
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('campaign_id', $response);
        $this->assertArrayHasKey('messages_count', $response);
        $this->assertArrayHasKey('messages', $response);
        $this->assertSame($campaignId, $response['campaign_id']);
        $this->assertGreaterThanOrEqual(3, $response['messages_count'], 'Should have at least 3 messages');

        // Verify message structure
        foreach ($response['messages'] as $message) {
            $this->assertArrayHasKey('msg_id', $message);
            $this->assertArrayHasKey('subject', $message);
            $this->assertArrayHasKey('from', $message);
            $this->assertArrayHasKey('body_preview', $message);
            $this->assertArrayHasKey('received_at', $message);
        }
    }

    /**
     * @group endtoend
     * @group llm
     */
    public function testCompleteLlmWorkflow(): void
    {
        $this->markTestSkipped('Requires OpenAI API key - run manually with OPENAI_API_KEY env var');

        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client);
        $jwt = $this->getValidJwt($client);

        // Step 1: Profile the campaign
        $client->request(
            'POST',
            '/api/v1/campaign/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'campaign_id' => $campaignId,
                'sample_limit' => 10,
            ])
        );

        $this->assertResponseIsSuccessful();
        $profileResponse = json_decode($client->getResponse()->getContent(), true);

        // Verify profile response structure
        $this->assertArrayHasKey('yaml', $profileResponse);
        $this->assertArrayHasKey('model', $profileResponse);
        $this->assertArrayHasKey('cached', $profileResponse);
        $this->assertSame('gpt-4o-mini', $profileResponse['model']);
        $this->assertFalse($profileResponse['cached'], 'First call should not be cached');

        // Verify YAML structure
        $yaml = $profileResponse['yaml'];
        $this->assertStringContainsString('campaign:', $yaml);
        $this->assertStringContainsString('variants:', $yaml);
        $this->assertStringContainsString('infra:', $yaml);

        // Parse YAML to verify structure
        $parsed = \Symfony\Component\Yaml\Yaml::parse($yaml);
        $this->assertArrayHasKey('campaign', $parsed);
        $this->assertArrayHasKey('summary', $parsed['campaign']);
        $this->assertArrayHasKey('variants', $parsed);

        // Verify no PII in profile
        $this->assertStringNotContainsString('@example.com', $yaml, 'Should not contain victim email');
        $this->assertStringNotContainsString('@scam.test', $yaml, 'Should not contain attacker email');

        // Step 2: Verify profile is cached on second call
        $client->request(
            'POST',
            '/api/v1/campaign/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'campaign_id' => $campaignId,
                'sample_limit' => 10,
            ])
        );

        $this->assertResponseIsSuccessful();
        $cachedResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($cachedResponse['cached'], 'Second call should be cached');
        $this->assertSame($yaml, $cachedResponse['yaml'], 'Cached YAML should be identical');

        // Step 3: Compile rules from profile
        $client->request(
            'POST',
            '/api/v1/campaign/compile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'campaign_id' => $campaignId,
                'examples' => [
                    'pos' => [
                        ['subject' => 'Urgent Account Alert', 'body' => 'Verify your account', 'dkim' => 'fail'],
                    ],
                    'neg' => [
                        ['subject' => 'Your order has shipped', 'body' => 'Tracking number: 123', 'dkim' => 'pass'],
                    ],
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
        $compileResponse = json_decode($client->getResponse()->getContent(), true);

        // Verify compile response structure
        $this->assertArrayHasKey('dsl', $compileResponse);
        $this->assertArrayHasKey('tests', $compileResponse);
        $this->assertArrayHasKey('model', $compileResponse);
        $this->assertSame('gpt-4o-mini', $compileResponse['model']);

        // Verify DSL structure
        $dsl = $compileResponse['dsl'];
        $this->assertStringContainsString('RULE', $dsl, 'DSL should contain RULE keyword');
        $this->assertStringContainsString('WHERE', $dsl, 'DSL should contain WHERE clause');
        $this->assertStringContainsString('ACTION', $dsl, 'DSL should contain ACTION clause');

        // Verify auto-tests
        $this->assertArrayHasKey('positive', $compileResponse['tests']);
        $this->assertArrayHasKey('negative', $compileResponse['tests']);

        // Step 4: Verify profile_yaml is stored in database
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = $em->find(\App\Domain\CampaignRadar\Campaign::class, $campaignId);
        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->getProfileYaml(), 'Profile YAML should be stored in campaign');
        $this->assertSame($yaml, $campaign->getProfileYaml(), 'Stored YAML should match profiler output');
    }

    /**
     * @group endtoend
     */
    public function testProfileCampaignWithLessThan3MessagesReturnsError(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create campaign with only 1 message
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = new \App\Domain\CampaignRadar\Campaign('e2e-test-insufficient');
        $em->persist($campaign);
        $em->flush();

        $msgId = $this->createTestMessage($client, 'Test', 'Body');

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['msg_id' => $msgId])
        );

        // Try to profile with insufficient messages
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'sample_limit' => 10,
            ])
        );

        $this->assertResponseStatusCodeSame(404); // RuntimeException → 404
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('messages', $response['error']);
        $this->assertStringContainsString('minimum 3', $response['error']);
    }

    /**
     * @group endtoend
     */
    public function testCompileWithoutProfileReturnsError(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create campaign without profile
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = new \App\Domain\CampaignRadar\Campaign('e2e-test-no-profile');
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/rules/compile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'examples' => [],
            ])
        );

        $this->assertResponseStatusCodeSame(404); // RuntimeException → 404
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertStringContainsString('no profile', $response['error']);
        $this->assertStringContainsString('profiling first', $response['error']);
    }
}
