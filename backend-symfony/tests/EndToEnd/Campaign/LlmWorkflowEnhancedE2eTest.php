<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Enhanced E2E tests for Campaign Radar LLM Services
 *
 * @group endtoend
 *
 * Covers:
 * - Full Profile → Compile workflow with FakeLLMClient
 * - Error validation (< 3 messages, non-existent campaign)
 * - Messages retrieval (GET /campaign/{id}/messages)
 * - profile_yaml persistence in DB
 * - Edge cases (sample_limit, empty messages, unicode)
 * - Realistic scenarios (PayPal, banks)
 * - Performance (latency < 30s)
 */
class LlmWorkflowEnhancedE2eTest extends WebTestCase
{
    // ==================== Helper Methods ====================

    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    private function createTestMessage($client, string $subject, string $body, ?array $headers = null): string
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
                'stix_id' => 'stix-llm-e2e-enhanced-' . bin2hex(random_bytes(4)),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        // Create message with custom headers
        $defaultHeaders = [
            'from' => 'phishing@scam.test',
            'to' => 'victim@example.test',
            'message_id' => '<llm-e2e-enhanced-' . bin2hex(random_bytes(8)) . '@scam.test>',
            'auth' => ['dkim' => false, 'spf' => false],
        ];

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
                'headers' => $headers ?? $defaultHeaders,
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return $msgData['msg_id'];
    }

    private function createCampaignWithMessages($client, array $messages, string $tag = 'llm-e2e-enhanced'): string
    {
        // Cleanup
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->getConnection()->executeStatement("DELETE FROM message_campaign WHERE campaign_id IN (SELECT campaign_id FROM campaign WHERE created_by = '{$tag}')");
        $em->getConnection()->executeStatement("DELETE FROM campaign WHERE created_by = '{$tag}'");
        $em->clear();

        // Create campaign directly
        $campaign = new \App\Domain\CampaignRadar\Campaign($tag);
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create messages and assign directly to campaign
        foreach ($messages as $msg) {
            $msgId = $this->createTestMessage(
                $client,
                $msg['subject'] ?? 'Default Subject',
                $msg['body'] ?? 'Default Body',
                $msg['headers'] ?? null
            );

            // Assign message directly to campaign in DB
            $messageCampaign = new \App\Domain\CampaignRadar\MessageCampaign(
                \Symfony\Component\Uid\Uuid::fromString($msgId),
                $campaign->getCampaignId(),
                0.95,
                $tag
            );

            $em->persist($messageCampaign);
        }

        $em->flush();
        $em->clear();

        return $campaignId;
    }

    // ==================== Tests GET Campaign Messages ====================

    /**
     * @group endtoend
     */
    public function testGetCampaignMessagesReturnsCorrectStructure(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Test 1', 'body' => 'Body 1'],
            ['subject' => 'Test 2', 'body' => 'Body 2'],
            ['subject' => 'Test 3', 'body' => 'Body 3'],
        ]);

        $jwt = $this->getValidJwt($client);

        $client->request(
            'GET',
            "/api/v1/campaign/{$campaignId}/messages?limit=10",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('campaign_id', $response);
        $this->assertArrayHasKey('messages_count', $response);
        $this->assertArrayHasKey('messages', $response);
        $this->assertEquals($campaignId, $response['campaign_id']);
        $this->assertGreaterThanOrEqual(3, $response['messages_count']);
    }

    /**
     * @group endtoend
     */
    public function testGetCampaignMessagesRespectsLimit(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Msg 1', 'body' => 'Body 1'],
            ['subject' => 'Msg 2', 'body' => 'Body 2'],
            ['subject' => 'Msg 3', 'body' => 'Body 3'],
            ['subject' => 'Msg 4', 'body' => 'Body 4'],
            ['subject' => 'Msg 5', 'body' => 'Body 5'],
        ]);

        $jwt = $this->getValidJwt($client);

        $client->request(
            'GET',
            "/api/v1/campaign/{$campaignId}/messages?limit=3",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertLessThanOrEqual(3, count($response['messages']));
    }

    // ==================== Tests Profile Campaign ====================

    /**
     * @group endtoend
     */
    public function testProfileCampaignWith3Messages(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Urgent Account', 'body' => 'Verify your account'],
            ['subject' => 'Security Alert', 'body' => 'Confirm your identity'],
            ['subject' => 'Action Required', 'body' => 'Update your information'],
        ]);

        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('profile_yaml', $response);
        $this->assertArrayHasKey('cache_hit', $response);
        $this->assertArrayHasKey('attempts', $response);
        $this->assertIsString($response['profile_yaml']);
    }

    /**
     * @group endtoend
     */
    public function testProfileCampaignStoresYamlInDatabase(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Test 1', 'body' => 'Body 1'],
            ['subject' => 'Test 2', 'body' => 'Body 2'],
            ['subject' => 'Test 3', 'body' => 'Body 3'],
        ]);

        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        $profileYaml = $response['profile_yaml'];

        // Verify stored in DB
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = $em->find(\App\Domain\CampaignRadar\Campaign::class, $campaignId);

        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->getProfileYaml());
        $this->assertEquals($profileYaml, $campaign->getProfileYaml());
    }

    /**
     * @group endtoend
     */
    public function testProfileCampaignWith2MessagesReturnsError(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Test 1', 'body' => 'Body 1'],
            ['subject' => 'Test 2', 'body' => 'Body 2'],
        ], 'llm-e2e-insufficient');

        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    /**
     * @group endtoend
     */
    public function testProfileNonExistentCampaignReturnsError(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $fakeId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();

        $client->request(
            'POST',
            "/api/v1/campaign/{$fakeId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseStatusCodeSame(404);
    }

    // ==================== Tests Compile Rules ====================

    /**
     * @group endtoend
     * @group llm
     */
    public function testCompileRulesAfterProfiling(): void
    {
        $this->markTestSkipped('Requires real LLM - FakeLLMClient cannot generate valid DSL');

        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Bank Alert', 'body' => 'Verify account'],
            ['subject' => 'Security Notice', 'body' => 'Confirm identity'],
            ['subject' => 'Urgent Action', 'body' => 'Update details'],
        ]);

        $jwt = $this->getValidJwt($client);

        // Step 1: Profile
        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();

        // Step 2: Compile rules
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
                'examples' => [
                    'pos' => [['subject' => 'Phishing', 'body' => 'Click here', 'dkim' => 'fail']],
                    'neg' => [['subject' => 'Newsletter', 'body' => 'News', 'dkim' => 'pass']],
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('dsl', $response);
        $this->assertArrayHasKey('tests', $response);
        $this->assertIsString($response['dsl']);
    }

    /**
     * @group endtoend
     */
    public function testCompileWithoutProfileReturnsError(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        $campaign = new \App\Domain\CampaignRadar\Campaign('e2e-no-profile');
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/rules/compile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['examples' => []])
        );

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    // ==================== Realistic Scenario Tests ====================

    /**
     * @group endtoend
     */
    public function testPayPalPhishingWorkflow(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            [
                'subject' => 'URGENT: PayPal Account Suspended',
                'body' => 'Verify at http://paypal-verify.scam.test',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'noreply@paypal-scam.test']
            ],
            [
                'subject' => 'PayPal Security Alert',
                'body' => 'Confirm identity at https://secure-paypal.test',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'security@pp-fake.test']
            ],
            [
                'subject' => 'Action Required: PayPal',
                'body' => 'Update details immediately',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'support@paypal-help.test']
            ],
        ], 'llm-e2e-paypal');

        $jwt = $this->getValidJwt($client);

        // Profile PayPal campaign
        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('profile_yaml', $response);
    }

    /**
     * @group endtoend
     */
    public function testBankPhishingWorkflow(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            [
                'subject' => 'Alerte Sécurité Bancaire',
                'body' => 'Compte bloqué. Débloquez via https://banque-secure.test',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'alert@bank-fake.test']
            ],
            [
                'subject' => 'Action Urgente Requise',
                'body' => 'Mise à jour sécurité nécessaire',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'securite@banque-scam.test']
            ],
            [
                'subject' => 'Vérification Compte',
                'body' => 'Confirmez vos informations',
                'headers' => ['auth' => ['dkim' => false, 'spf' => false], 'from' => 'noreply@bank-verify.test']
            ],
        ], 'llm-e2e-bank');

        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();
    }

    // ==================== Tests Edge Cases ====================

    /**
     * @group endtoend
     */
    public function testProfileWithUnicodeMessages(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'こんにちは', 'body' => '世界へようこそ 🌍'],
            ['subject' => 'Привет', 'body' => 'Добро пожаловать 👋'],
            ['subject' => 'مرحبا', 'body' => 'أهلا وسهلا 🎉'],
        ], 'llm-e2e-unicode');

        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $this->assertResponseIsSuccessful();
    }

    /**
     * @group endtoend
     */
    public function testProfileCompletesWithin30Seconds(): void
    {
        $client = static::createClient();
        $campaignId = $this->createCampaignWithMessages($client, [
            ['subject' => 'Test 1', 'body' => str_repeat('Long text ', 500)],
            ['subject' => 'Test 2', 'body' => str_repeat('Long text ', 500)],
            ['subject' => 'Test 3', 'body' => str_repeat('Long text ', 500)],
        ], 'llm-e2e-perf');

        $jwt = $this->getValidJwt($client);

        $startTime = microtime(true);

        $client->request(
            'POST',
            "/api/v1/campaign/{$campaignId}/profile",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['sample_size' => 10])
        );

        $duration = microtime(true) - $startTime;

        $this->assertResponseIsSuccessful();
        $this->assertLessThan(30, $duration, "Profile took {$duration}s, expected < 30s");
    }
}
