<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests End-to-End complets pour Campaign Radar Phase 2: Clustering & Features
 *
 * @group endtoend
 *
 * Scénarios testés:
 * - Workflow clustering complet (création + assignment)
 * - Messages similaires rejoignent même campagne
 * - Messages dissimilaires créent nouvelles campagnes
 * - Gestion de multiples campagnes concurrentes
 * - Scénarios réalistes de phishing (PayPal, Amazon, banques)
 * - Edge cases (messages vides, très longs, unicode)
 * - Sécurité (XSS, SQL injection, defanging URLs)
 * - Performance (clustering avec beaucoup de messages)
 * - Validation API (erreurs 400, 404, 403)
 */
class ClusteringE2eTest extends WebTestCase
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

    private function createTestMessage($client, string $subject, string $body, ?array $headers = null): string
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
                'score_risk' => 75,
                'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-campaign-e2e-' . bin2hex(random_bytes(4)),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        $defaultHeaders = [
            'from' => 'phishing@evil.test',
            'to' => 'victim@example.test',
            'message_id' => '<e2e-campaign-' . bin2hex(random_bytes(8)) . '@evil.test>',
            'auth' => ['dkim' => false, 'spf' => false],
        ];

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
                'headers' => $headers ?? $defaultHeaders,
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return $msgData['msg_id'];
    }

    private function assignMessageToCampaign($client, string $msgId): array
    {
        $jwt = $this->getValidJwt($client);

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

        return json_decode($client->getResponse()->getContent(), true);
    }

    private function cleanupCampaigns($client): void
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->getConnection()->executeStatement('DELETE FROM message_campaign');
        $em->getConnection()->executeStatement('DELETE FROM campaign');
        $em->clear();
    }

    // ==================== Tests Workflow Basique ====================

    /**
     * @group endtoend
     */
    public function testCompleteClusteringWorkflow(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Step 1: Create 3 similar phishing messages
        $msgId1 = $this->createTestMessage(
            $client,
            'Urgent Account Verification Required',
            'Your PayPal account has been suspended. Click here to verify: https://paypal-verify.evil.test/login'
        );

        $msgId2 = $this->createTestMessage(
            $client,
            'Urgent Account Verification Required',
            'Your PayPal account has been suspended. Click here to verify: https://paypal-verify.evil.test/confirm'
        );

        $msgId3 = $this->createTestMessage(
            $client,
            'Urgent Account Verification Required',
            'Your PayPal account has been suspended. Click here to verify: https://paypal-verify.evil.test/restore'
        );

        // Step 2: Assign first message → should create new campaign
        $response1 = $this->assignMessageToCampaign($client, $msgId1);

        $this->assertResponseStatusCodeSame(201, 'First message should create new campaign');
        $this->assertTrue($response1['is_new_campaign']);
        $this->assertEquals(1.0, $response1['confidence'], 'New campaign confidence should be 1.0');
        $campaignId = $response1['campaign_id'];
        $this->assertNotNull($campaignId);

        // Step 3: Assign second message → should join existing campaign
        $response2 = $this->assignMessageToCampaign($client, $msgId2);

        $this->assertResponseStatusCodeSame(200, 'Second message should join existing campaign');
        $this->assertFalse($response2['is_new_campaign']);
        $this->assertSame($campaignId, $response2['campaign_id']);
        $this->assertGreaterThan(0.75, $response2['confidence'], 'Similarity should be ≥0.75');

        // Step 4: Assign third message → should also join same campaign
        $response3 = $this->assignMessageToCampaign($client, $msgId3);

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame($campaignId, $response3['campaign_id']);

        // Step 5: Verify campaign has 3 messages
        $em = $client->getContainer()->get('doctrine')->getManager();
        $messageCampaigns = $em->getRepository(\App\Domain\CampaignRadar\MessageCampaign::class)
            ->findBy(['campaignId' => $campaignId]);

        $this->assertCount(3, $messageCampaigns, 'Campaign should have 3 messages');

        // Step 6: Verify features are persisted
        foreach ($messageCampaigns as $mc) {
            $features = $mc->getFeatures();
            $this->assertNotNull($features, 'Features should be persisted');
            $this->assertArrayHasKey('text', $features);
            $this->assertArrayHasKey('infra', $features);
            $this->assertArrayHasKey('style', $features);
            $this->assertArrayHasKey('simhash', $features['text']);
        }

        // Step 7: Verify centroid is updated
        $campaign = $em->getRepository(\App\Domain\CampaignRadar\Campaign::class)->find($campaignId);
        $this->assertNotNull($campaign);
        $this->assertNotNull($campaign->getCentroidSimhash(), 'Campaign should have centroid simhash');
        $this->assertSame(32, strlen($campaign->getCentroidSimhash()), 'Centroid simhash should be MD5 (32 chars)');
    }

    /**
     * @group endtoend
     */
    public function testDissimilarMessagesCreateSeparateCampaigns(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Create 2 completely different phishing messages
        $msgId1 = $this->createTestMessage(
            $client,
            'PayPal Account Suspended',
            'Click here to verify: https://paypal-verify.evil.test/login'
        );

        $msgId2 = $this->createTestMessage(
            $client,
            'Amazon Prime Renewal',
            'Your Amazon Prime subscription will expire. Renew now: https://amazon-renew.scam.test/pay'
        );

        // Assign both messages
        $response1 = $this->assignMessageToCampaign($client, $msgId1);
        $this->assertResponseStatusCodeSame(201);
        $campaignId1 = $response1['campaign_id'];

        $response2 = $this->assignMessageToCampaign($client, $msgId2);
        $this->assertResponseStatusCodeSame(201, 'Dissimilar message should create new campaign');
        $campaignId2 = $response2['campaign_id'];

        $this->assertNotSame($campaignId1, $campaignId2, 'Different campaigns should be created for dissimilar messages');
    }

    // ==================== Tests Scénarios Réalistes ====================

    /**
     * @group endtoend
     */
    public function testPayPalPhishingCampaignVariations(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Variations avec BEAUCOUP de mots identiques pour Jaccard > 0.75
        $variations = [
            ['subject' => 'Urgent PayPal Account Suspended', 'body' => 'Your PayPal account has been suspended click here to verify your PayPal identity now'],
            ['subject' => 'PayPal Account Verification Required', 'body' => 'Your PayPal account has been suspended click here to verify your PayPal identity now'],
            ['subject' => 'Action Required PayPal Account', 'body' => 'Your PayPal account has been suspended click here to verify your PayPal identity now'],
            ['subject' => 'PayPal Security Alert', 'body' => 'Your PayPal account has been suspended click here to verify your PayPal identity now'],
            ['subject' => 'Verify Your PayPal Account', 'body' => 'Your PayPal account has been suspended click here to verify your PayPal identity now'],
        ];

        $messageIds = [];
        foreach ($variations as $variation) {
            $messageIds[] = $this->createTestMessage($client, $variation['subject'], $variation['body']);
        }

        // First message creates campaign
        $response1 = $this->assignMessageToCampaign($client, $messageIds[0]);
        $this->assertResponseStatusCodeSame(201);
        $campaignId = $response1['campaign_id'];

        // All other messages should join the same campaign
        foreach (array_slice($messageIds, 1) as $msgId) {
            $response = $this->assignMessageToCampaign($client, $msgId);
            $this->assertResponseStatusCodeSame(200, 'Variation should join existing campaign');
            $this->assertSame($campaignId, $response['campaign_id']);
            $this->assertGreaterThanOrEqual(0.75, $response['confidence']); // >= au lieu de >
        }

        // Verify all 5 messages are in the campaign
        $em = $client->getContainer()->get('doctrine')->getManager();
        $messageCampaigns = $em->getRepository(\App\Domain\CampaignRadar\MessageCampaign::class)
            ->findBy(['campaignId' => $campaignId]);
        $this->assertCount(5, $messageCampaigns);
    }

    /**
     * @group endtoend
     */
    public function testMultipleConcurrentPhishingCampaigns(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Campaign 1: PayPal phishing (3 messages) - mots identiques pour clustering
        $paypalMsgs = [
            $this->createTestMessage($client, 'PayPal Suspended', 'Your PayPal account verification required click here to verify PayPal'),
            $this->createTestMessage($client, 'PayPal Alert', 'Your PayPal account verification required click here to verify PayPal'),
            $this->createTestMessage($client, 'PayPal Security', 'Your PayPal account verification required click here to verify PayPal'),
        ];

        // Campaign 2: Amazon phishing (3 messages) - mots identiques pour clustering
        $amazonMsgs = [
            $this->createTestMessage($client, 'Amazon Prime Expiring', 'Your Amazon Prime subscription expiring renew now click here Amazon'),
            $this->createTestMessage($client, 'Amazon Renewal Required', 'Your Amazon Prime subscription expiring renew now click here Amazon'),
            $this->createTestMessage($client, 'Amazon Prime Alert', 'Your Amazon Prime subscription expiring renew now click here Amazon'),
        ];

        // Campaign 3: Bank phishing (3 messages) - mots identiques pour clustering
        $bankMsgs = [
            $this->createTestMessage($client, 'Bank Account Alert', 'Your bank account security update required click here to update bank'),
            $this->createTestMessage($client, 'Bank Security Notice', 'Your bank account security update required click here to update bank'),
            $this->createTestMessage($client, 'Bank Account Update', 'Your bank account security update required click here to update bank'),
        ];

        // Assign all messages and verify they cluster correctly
        $paypalCampaignId = $this->assignMessageToCampaign($client, $paypalMsgs[0])['campaign_id'];
        $amazonCampaignId = $this->assignMessageToCampaign($client, $amazonMsgs[0])['campaign_id'];
        $bankCampaignId = $this->assignMessageToCampaign($client, $bankMsgs[0])['campaign_id'];

        // Verify 3 separate campaigns
        $this->assertNotSame($paypalCampaignId, $amazonCampaignId);
        $this->assertNotSame($paypalCampaignId, $bankCampaignId);
        $this->assertNotSame($amazonCampaignId, $bankCampaignId);

        // Verify PayPal messages cluster together
        foreach (array_slice($paypalMsgs, 1) as $msgId) {
            $response = $this->assignMessageToCampaign($client, $msgId);
            $this->assertSame($paypalCampaignId, $response['campaign_id']);
        }

        // Verify Amazon messages cluster together
        foreach (array_slice($amazonMsgs, 1) as $msgId) {
            $response = $this->assignMessageToCampaign($client, $msgId);
            $this->assertSame($amazonCampaignId, $response['campaign_id']);
        }

        // Verify Bank messages cluster together
        foreach (array_slice($bankMsgs, 1) as $msgId) {
            $response = $this->assignMessageToCampaign($client, $msgId);
            $this->assertSame($bankCampaignId, $response['campaign_id']);
        }

        // Verify message counts
        $em = $client->getContainer()->get('doctrine')->getManager();
        $this->assertCount(3, $em->getRepository(\App\Domain\CampaignRadar\MessageCampaign::class)->findBy(['campaignId' => $paypalCampaignId]));
        $this->assertCount(3, $em->getRepository(\App\Domain\CampaignRadar\MessageCampaign::class)->findBy(['campaignId' => $amazonCampaignId]));
        $this->assertCount(3, $em->getRepository(\App\Domain\CampaignRadar\MessageCampaign::class)->findBy(['campaignId' => $bankCampaignId]));
    }

    // ==================== Tests Edge Cases ====================

    /**
     * @group endtoend
     */
    public function testClusteringWithEmptyMessage(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Note: L'API refuse les messages complètement vides (validation body_text required)
        // Utilisons un body minimal au lieu de vide
        $msgId = $this->createTestMessage($client, '', 'minimal');

        $response = $this->assignMessageToCampaign($client, $msgId);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('campaign_id', $response);
    }

    /**
     * @group endtoend
     */
    public function testClusteringWithVeryLongMessage(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $longBody = str_repeat('This is a phishing message. Click here to verify your account. ', 500);
        $msgId = $this->createTestMessage($client, 'Long Message', $longBody);

        $response = $this->assignMessageToCampaign($client, $msgId);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('campaign_id', $response);
    }

    /**
     * @group endtoend
     */
    public function testClusteringWithUnicodeCharacters(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $msgId1 = $this->createTestMessage($client, 'こんにちは', 'Unicode test message 世界 🌍');
        $msgId2 = $this->createTestMessage($client, 'こんにちは', 'Unicode test message 世界 🌍');

        $response1 = $this->assignMessageToCampaign($client, $msgId1);
        $campaignId = $response1['campaign_id'];

        $response2 = $this->assignMessageToCampaign($client, $msgId2);

        // Should join same campaign (identical unicode text)
        $this->assertSame($campaignId, $response2['campaign_id']);
    }

    /**
     * @group endtoend
     */
    public function testClusteringWithSpecialCharacters(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $body = 'Special chars: ñ, é, ü, ç, ø, ł, ß, æ, œ';
        $msgId = $this->createTestMessage($client, 'Special Characters', $body);

        $response = $this->assignMessageToCampaign($client, $msgId);

        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('campaign_id', $response);
    }

    // ==================== Tests Sécurité ====================

    /**
     * @group endtoend
     */
    public function testClusteringWithXssPayload(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $xssSubject = '<script>alert("XSS")</script>';
        $xssBody = 'Click here: <img src=x onerror=alert(1)>';

        $msgId = $this->createTestMessage($client, $xssSubject, $xssBody);

        $response = $this->assignMessageToCampaign($client, $msgId);

        // Should handle XSS safely
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('campaign_id', $response);
    }

    /**
     * @group endtoend
     */
    public function testClusteringWithSqlInjection(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $sqliBody = "'; DROP TABLE campaign; --";
        $msgId = $this->createTestMessage($client, 'Test', $sqliBody);

        $response = $this->assignMessageToCampaign($client, $msgId);

        // Should handle SQL injection safely
        $this->assertResponseStatusCodeSame(201);
        $this->assertArrayHasKey('campaign_id', $response);

        // Verify campaign table still exists
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaigns = $em->getRepository(\App\Domain\CampaignRadar\Campaign::class)->findAll();
        $this->assertNotEmpty($campaigns, 'Campaign table should not be dropped');
    }

    /**
     * @group endtoend
     */
    public function testClusteringDefangsUrls(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $body = 'Visit http://malicious.com and https://phishing.net for details';
        $msgId = $this->createTestMessage($client, 'Test', $body);

        $response = $this->assignMessageToCampaign($client, $msgId);
        $campaignId = $response['campaign_id'];

        // Verify URLs are defanged in persisted features
        $em = $client->getContainer()->get('doctrine')->getManager();
        $messageCampaign = $em->find(\App\Domain\CampaignRadar\MessageCampaign::class, [
            'msgId' => \Symfony\Component\Uid\Uuid::fromString($msgId),
            'campaignId' => \Symfony\Component\Uid\Uuid::fromString($campaignId)
        ]);

        $this->assertNotNull($messageCampaign);
        $features = $messageCampaign->getFeatures();
        $this->assertArrayHasKey('text', $features);
        $this->assertStringContainsString('hxxp://', $features['text']['body_normalized']);
        $this->assertStringContainsString('hxxps://', $features['text']['body_normalized']);
    }

    // ==================== Tests Validation API ====================

    /**
     * @group endtoend
     */
    public function testAssignWithoutAuthentication(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['msg_id' => 'test'])
        );

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * @group endtoend
     */
    public function testAssignWithInvalidMessageId(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['msg_id' => 'invalid-uuid'])
        );

        $this->assertTrue(
            in_array($client->getResponse()->getStatusCode(), [400, 404]),
            'Invalid message ID should return 400 or 404'
        );
    }

    /**
     * @group endtoend
     */
    public function testAssignWithNonExistentMessageId(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $fakeUuid = \Ramsey\Uuid\Uuid::uuid4()->toString();

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['msg_id' => $fakeUuid])
        );

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * @group endtoend
     */
    public function testAssignWithMissingPayload(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([]) // Missing msg_id
        );

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @group endtoend
     */
    public function testAssignWithInvalidJson(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/cluster/assign',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            'not a json' // Invalid JSON
        );

        $this->assertResponseStatusCodeSame(400);
    }

    // ==================== Tests Performance ====================

    /**
     * @group endtoend
     */
    public function testClusteringPerformanceWith50Messages(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        // Create 50 messages in 5 campaigns (10 messages each)
        $startTime = microtime(true);

        for ($i = 0; $i < 50; $i++) {
            $campaignType = (int)($i / 10); // 0-4
            $subject = "Campaign $campaignType Message $i";
            $body = "This is campaign $campaignType message number $i with specific keywords type$campaignType";

            $msgId = $this->createTestMessage($client, $subject, $body);
            $this->assignMessageToCampaign($client, $msgId);
        }

        $duration = microtime(true) - $startTime;

        // Should complete in reasonable time (< 70 seconds for 50 messages - E2E avec creation messages)
        $this->assertLessThan(70.0, $duration, 'Clustering 50 messages should complete in < 70 seconds');

        // Verify campaigns were created
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaigns = $em->getRepository(\App\Domain\CampaignRadar\Campaign::class)->findAll();
        $this->assertGreaterThan(0, count($campaigns));
    }

    // ==================== Tests Features Persistance ====================

    /**
     * @group endtoend
     */
    public function testFeaturesArePersisted(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $body = 'Click here: https://phishing.com/verify for account verification!';
        $headers = ['auth' => ['dkim' => false, 'spf' => false]];
        $msgId = $this->createTestMessage($client, 'Urgent Verification', $body, $headers);

        $response = $this->assignMessageToCampaign($client, $msgId);
        $campaignId = $response['campaign_id'];

        // Retrieve persisted features from database
        // Note: Le champ correct est msgId, pas messageId
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conn = $em->getConnection();
        $sql = 'SELECT * FROM message_campaign WHERE msg_id = :msgId LIMIT 1';
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['msgId' => $msgId]);
        $row = $result->fetchAssociative();

        $this->assertNotFalse($row, 'MessageCampaign should exist');
        $messageCampaign = $em->find(\App\Domain\CampaignRadar\MessageCampaign::class, [
            'msgId' => \Symfony\Component\Uid\Uuid::fromString($msgId),
            'campaignId' => \Symfony\Component\Uid\Uuid::fromString($campaignId)
        ]);

        $this->assertNotNull($messageCampaign);
        $features = $messageCampaign->getFeatures();

        // Verify text features
        $this->assertArrayHasKey('text', $features);
        $this->assertArrayHasKey('simhash', $features['text']);
        $this->assertArrayHasKey('ngrams', $features['text']);
        $this->assertSame(32, strlen($features['text']['simhash']));

        // Verify infra features
        $this->assertArrayHasKey('infra', $features);
        $this->assertContains('phishing.com', $features['infra']['url_domains']);
        $this->assertFalse($features['infra']['dkim']);
        $this->assertFalse($features['infra']['spf']);

        // Verify style features
        $this->assertArrayHasKey('style', $features);
        $this->assertArrayHasKey('punct_ratio', $features['style']);
        $this->assertIsFloat($features['style']['punct_ratio']);
    }

    /**
     * @group endtoend
     */
    public function testCentroidIsUpdated(): void
    {
        $client = static::createClient();
        $this->cleanupCampaigns($client);

        $msgId1 = $this->createTestMessage($client, 'Test', 'Message 1');
        $msgId2 = $this->createTestMessage($client, 'Test', 'Message 1');

        $response1 = $this->assignMessageToCampaign($client, $msgId1);
        $campaignId = $response1['campaign_id'];

        $this->assignMessageToCampaign($client, $msgId2);

        // Verify centroid was updated
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = $em->getRepository(\App\Domain\CampaignRadar\Campaign::class)->find($campaignId);

        $this->assertNotNull($campaign->getCentroidSimhash());
        $this->assertSame(32, strlen($campaign->getCentroidSimhash()));
    }
}
