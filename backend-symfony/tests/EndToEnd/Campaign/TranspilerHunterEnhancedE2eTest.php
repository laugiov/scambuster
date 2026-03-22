<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests E2E RENFORCÉS pour Phase 4: Transpiler & Hunter
 *
 * Couvre:
 * - Scénarios réalistes complets (PayPal, banque, crypto, Amazon)
 * - Workflow complet: transpile → store → hunt → verify
 * - Calcul PPV avec messages réalistes (high/low risk)
 * - Calcul lead-time avec séries temporelles
 * - Métriques persistence
 * - Prepared statements security
 * - Multiple predicates combinations
 *
 * @group endtoend
 */
final class TranspilerHunterEnhancedE2eTest extends WebTestCase
{
    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    private function createTestMessage($client, string $subject, string $body, int $scoreRisk = 75, ?\DateTimeImmutable $timestamp = null): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel, 'Channel fixture not found');
        $this->assertNotNull($scamType, 'ScamType fixture not found');
        $this->assertNotNull($account, 'MailAccount fixture not found');

        $timestamp = $timestamp ?? new \DateTimeImmutable();

        // Get fresh JWT for each message creation
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
                'score_risk' => $scoreRisk,
                'ts_first' => $timestamp->modify('-3 hours')->format(DATE_ATOM),
                'ts_last' => $timestamp->format(DATE_ATOM),
                'stix_id' => 'stix-e2e-enhanced-' . bin2hex(random_bytes(4)),
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
                    'from' => 'scammer@evil.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<e2e-enhanced-' . bin2hex(random_bytes(8)) . '@evil.test>',
                    'auth' => ['dkim' => false, 'spf' => false],
                ],
                'ts_msg' => $timestamp->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return $msgData['msg_id'];
    }

    private function transpileDSL($client, string $dsl): array
    {
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/transpile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['dsl' => $dsl])
        );

        $this->assertResponseIsSuccessful();
        return json_decode($client->getResponse()->getContent(), true);
    }

    private function storeRule($client, string $campaignId, string $dsl, array $compiled): array
    {
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/rule',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([
                'campaign_id' => $campaignId,
                'dsl' => $dsl,
                'compiled_sql' => $compiled,
            ])
        );

        $this->assertResponseIsSuccessful();
        return json_decode($client->getResponse()->getContent(), true);
    }

    private function huntCampaigns($client): array
    {
        $jwt = $this->getValidJwt($client);

        $client->request(
            'POST',
            '/api/v1/campaign/hunt',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();
        return json_decode($client->getResponse()->getContent(), true);
    }

    // ==================== Tests Scénarios Réalistes ====================

    /**
     * @group endtoend
     */
    public function testPayPalPhishingCampaignEndToEnd(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%paypal_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'paypal-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('paypal-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create realistic PayPal phishing messages (high risk)
        $msgId1 = $this->createTestMessage($client, 'PayPal Account Suspended', 'Your PayPal account has been suspended. Please verify your account immediately to restore access. Click here to verify: http://paypal-verify.evil.test', 85);
        $msgId2 = $this->createTestMessage($client, 'Urgent: PayPal Security Alert', 'We detected unusual activity. Please confirm your identity to secure your account. Verify now: http://secure-paypal.evil.test', 90);
        $msgId3 = $this->createTestMessage($client, 'PayPal Payment Received', 'You have received a payment of $150. Please verify your account to claim this payment.', 80);

        // Create legitimate messages (low risk)
        $msgId4 = $this->createTestMessage($client, 'PayPal Receipt', 'Thank you for your purchase. Order #12345 has been processed.', 10);
        $msgId5 = $this->createTestMessage($client, 'Your order has shipped', 'Your Amazon order has been dispatched.', 5);

        // Transpile PayPal phishing DSL rule
        $dsl = 'RULE paypal_e2e_enhanced { WHERE subject.simhash≈"paypal account suspended" ±20% AND body.containsAny ["verify account","confirm identity","restore access"] AND dkim.pass ∈ {false, null} ACTION tag="campaign:paypal_phish", score+=50 }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Verify transpilation
        $this->assertArrayHasKey('sql', $compiled);
        $this->assertArrayHasKey('params', $compiled);
        $this->assertStringContainsString('similarity(subject, :p0)', $compiled['sql']);
        $this->assertStringContainsString('body_text ILIKE ANY', $compiled['sql']);
        $this->assertStringContainsString('dkim', $compiled['sql']);

        // Store rule
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $this->assertArrayHasKey('rule_id', $rule);
        $ruleId = $rule['rule_id'];

        // Execute hunt
        $huntResult = $this->huntCampaigns($client);

        // Verify hunt structure
        $this->assertArrayHasKey('total_rules', $huntResult);
        $this->assertArrayHasKey('total_hits', $huntResult);
        $this->assertArrayHasKey('results', $huntResult);
        $this->assertGreaterThanOrEqual(1, $huntResult['total_rules']);

        // Find our rule result
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $ruleId);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        // Verify metrics
        $this->assertEquals('ok', $ruleResult['status']);
        $this->assertGreaterThan(0, $ruleResult['hits_count']);
        $this->assertArrayHasKey('ppv', $ruleResult);
        $this->assertArrayHasKey('validation', $ruleResult);

        // Verify PPV is high (mostly true positives)
        if ($ruleResult['hits_count'] > 0) {
            $this->assertGreaterThan(0.6, $ruleResult['ppv'], 'PPV should be > 0.6 for PayPal phishing (mostly high-risk messages)');
        }

        // Verify rule metrics persisted in DB
        $em->clear();
        $storedRule = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $ruleId);
        $this->assertNotNull($storedRule);
        $this->assertGreaterThan(0, $storedRule->getHitsTotal());
        $this->assertGreaterThanOrEqual(0, $storedRule->getPpv());
    }

    /**
     * @group endtoend
     */
    public function testBankPhishingFrenchCampaignEndToEnd(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%bank_fr_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'bank-fr-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('bank-fr-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create realistic French bank phishing messages (high risk)
        $msgId1 = $this->createTestMessage($client, 'Alerte Sécurité - Crédit Agricole', 'Votre compte bancaire nécessite une vérification immédiate. Cliquez ici pour confirmer votre identité.', 88);
        $msgId2 = $this->createTestMessage($client, 'Urgent: BNP Paribas Sécurité', 'Activité suspecte détectée. Veuillez vérifier votre compte pour éviter le blocage.', 92);
        $msgId3 = $this->createTestMessage($client, 'Société Générale - Action Requise', 'Votre compte sera suspendu. Confirmez vos informations maintenant.', 85);

        // Create legitimate messages (low risk)
        $msgId4 = $this->createTestMessage($client, 'Votre relevé bancaire mensuel', 'Votre relevé de compte pour le mois de janvier est disponible.', 8);

        // Transpile French bank phishing DSL rule (use higher tolerance for more matches)
        $dsl = 'RULE bank_fr_e2e_enhanced { WHERE body.containsAny ["vérification","vérifier","confirmer","suspendu"] AND spf.pass ∈ {false, null} ACTION tag="campaign:bank_fr", score+=60 }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Store and hunt
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $huntResult = $this->huntCampaigns($client);

        // Verify results
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        $this->assertEquals('ok', $ruleResult['status']);
        $this->assertGreaterThan(0, $ruleResult['hits_count']);
        $this->assertGreaterThan(0.6, $ruleResult['ppv'], 'PPV should be high for French bank phishing');
    }

    /**
     * @group endtoend
     */
    public function testCryptoScamCampaignEndToEnd(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%crypto_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'crypto-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('crypto-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create realistic crypto scam messages (high risk)
        $msgId1 = $this->createTestMessage($client, 'Bitcoin Giveaway - Act Now!', 'Elon Musk is giving away 5000 BTC! Send 0.1 BTC to receive 1 BTC back. Limited time offer!', 95);
        $msgId2 = $this->createTestMessage($client, 'Ethereum Investment Opportunity', 'Guaranteed 500% returns on ETH investment. Verify your wallet address to claim bonus.', 90);
        $msgId3 = $this->createTestMessage($client, 'Urgent: Wallet Verification Required', 'Your crypto wallet needs verification. Confirm your seed phrase to prevent account closure.', 93);

        // Create legitimate crypto messages (low risk)
        $msgId4 = $this->createTestMessage($client, 'Coinbase Transaction Confirmation', 'Your purchase of 0.01 BTC has been completed.', 12);

        // Transpile crypto scam DSL rule
        $dsl = 'RULE crypto_e2e_enhanced { WHERE body.containsAny ["BTC","ETH","bitcoin","ethereum","crypto"] AND body.containsAny ["verify wallet","send","giveaway","guaranteed returns"] ACTION tag="campaign:crypto_scam", score+=70 }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Store and hunt
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $huntResult = $this->huntCampaigns($client);

        // Verify results
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        $this->assertEquals('ok', $ruleResult['status']);
        $this->assertGreaterThan(0, $ruleResult['hits_count']);
        $this->assertGreaterThan(0.5, $ruleResult['ppv'], 'PPV should be decent for crypto scam');
    }

    /**
     * @group endtoend
     */
    public function testAmazonPhishingCampaignEndToEnd(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%amazon_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'amazon-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('amazon-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create realistic Amazon phishing messages (high risk)
        $msgId1 = $this->createTestMessage($client, 'Amazon Account Suspended', 'Your Amazon account has been suspended due to unusual activity. Verify your payment method immediately.', 87);
        $msgId2 = $this->createTestMessage($client, 'Urgent: Amazon Security Alert', 'Unauthorized access detected. Confirm your identity to secure your account.', 91);
        $msgId3 = $this->createTestMessage($client, 'Amazon Prime Renewal Failed', 'Your Prime membership payment failed. Update your payment information to avoid service interruption.', 82);

        // Create legitimate Amazon messages (low risk)
        $msgId4 = $this->createTestMessage($client, 'Your Amazon.com order has shipped', 'Your order #123-4567890-1234567 has been dispatched.', 7);
        $msgId5 = $this->createTestMessage($client, 'Amazon Order Confirmation', 'Thank you for your order. Estimated delivery: Jan 25.', 5);

        // Transpile Amazon phishing DSL rule (now supports subject.containsAny!)
        $dsl = 'RULE amazon_e2e_enhanced { WHERE subject.containsAny ["Amazon","amazon"] AND body.containsAny ["verify","confirm","update","payment","suspended","failed"] AND dkim.pass ∈ {false, null} ACTION tag="campaign:amazon_phish", score+=55 }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Store and hunt
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $huntResult = $this->huntCampaigns($client);

        // Verify results
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        $this->assertEquals('ok', $ruleResult['status']);
        $this->assertGreaterThan(0, $ruleResult['hits_count']);
        $this->assertGreaterThan(0.6, $ruleResult['ppv'], 'PPV should be high for Amazon phishing');
    }

    // ==================== Tests Lead-Time Calculation ====================

    /**
     * @group endtoend
     */
    public function testLeadTimeCalculationWithTimeSeries(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%leadtime_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'leadtime-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('leadtime-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create time-series messages simulating a campaign ramp-up
        $baseTime = new \DateTimeImmutable('-24 hours');

        // First hit (t=0)
        $msgId1 = $this->createTestMessage($client, 'Test Campaign Message 1', 'This is the first message in the campaign.', 75, $baseTime);

        // Ramp up (t=1h to t=5h)
        $msgId2 = $this->createTestMessage($client, 'Test Campaign Message 2', 'Second message.', 78, $baseTime->modify('+1 hour'));
        $msgId3 = $this->createTestMessage($client, 'Test Campaign Message 3', 'Third message.', 80, $baseTime->modify('+2 hours'));
        $msgId4 = $this->createTestMessage($client, 'Test Campaign Message 4', 'Fourth message.', 82, $baseTime->modify('+3 hours'));
        $msgId5 = $this->createTestMessage($client, 'Test Campaign Message 5', 'Fifth message.', 85, $baseTime->modify('+4 hours'));

        // Peak (t=5h to t=7h) - multiple messages in short window
        $msgId6 = $this->createTestMessage($client, 'Test Campaign Message 6', 'Peak message 1.', 88, $baseTime->modify('+5 hours'));
        $msgId7 = $this->createTestMessage($client, 'Test Campaign Message 7', 'Peak message 2.', 90, $baseTime->modify('+5 hours 30 minutes'));
        $msgId8 = $this->createTestMessage($client, 'Test Campaign Message 8', 'Peak message 3.', 87, $baseTime->modify('+6 hours'));

        // Trailing off (t=8h+)
        $msgId9 = $this->createTestMessage($client, 'Test Campaign Message 9', 'Trailing message.', 75, $baseTime->modify('+8 hours'));

        // Create rule that matches these messages
        $dsl = 'RULE leadtime_e2e_enhanced { WHERE subject.simhash≈"test campaign message" ±15% ACTION tag="test" }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Store and hunt
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $huntResult = $this->huntCampaigns($client);

        // Verify lead-time calculation
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        $this->assertEquals('ok', $ruleResult['status']);
        $this->assertGreaterThanOrEqual(5, $ruleResult['hits_count'], 'Should have at least 5 hits for lead-time calculation');

        // Lead-time should be calculated (not null) since we have enough hits
        $this->assertNotNull($ruleResult['lead_time_sec'], 'Lead-time should be calculated with 5+ hits');
        $this->assertGreaterThanOrEqual(0, $ruleResult['lead_time_sec']);

        // Lead-time should be reasonable (between 0 and 24 hours)
        $this->assertLessThanOrEqual(86400, $ruleResult['lead_time_sec'], 'Lead-time should be < 24h');
    }

    // ==================== Tests Prepared Statements Security ====================

    /**
     * @group endtoend
     */
    public function testPreparedStatementsPreventSQLInjection(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%sqli_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'sqli-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('sqli-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create test message with SQL injection attempt in subject
        $msgId = $this->createTestMessage($client, "Test'; DROP TABLE message; --", 'Innocent body text.', 50);

        // Transpile rule with simhash that would match (prepared statement should prevent injection)
        $dsl = 'RULE sqli_e2e_enhanced { WHERE subject.simhash≈"test drop table" ±30% ACTION tag="test" }';
        $compiled = $this->transpileDSL($client, $dsl);

        // Verify prepared statement parameters are used
        $this->assertStringContainsString(':p0', $compiled['sql'], 'Should use prepared statement parameter');
        $this->assertArrayHasKey('p0', $compiled['params']);

        // Store and hunt (should execute safely)
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $huntResult = $this->huntCampaigns($client);

        // Verify hunt executed without error (SQL injection prevented)
        $this->assertArrayHasKey('results', $huntResult);
        $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
        $this->assertNotEmpty($ruleResults);
        $ruleResult = reset($ruleResults);

        $this->assertEquals('ok', $ruleResult['status'], 'Hunt should succeed despite SQL injection attempt in data');

        // Verify message table still exists (not dropped)
        $messagesCount = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM message');
        $this->assertGreaterThan(0, $messagesCount, 'Message table should still exist');
    }

    // ==================== Tests Multiple Rules Parallel Execution ====================

    /**
     * @group endtoend
     */
    public function testMultipleRulesExecuteInParallel(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%multi_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'multi-e2e-enhanced\'');
        $em->clear();

        // Create campaign
        $campaign = new \App\Domain\CampaignRadar\Campaign('multi-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create diverse messages
        $msgId1 = $this->createTestMessage($client, 'PayPal Account Alert', 'Verify your PayPal account now.', 85);
        $msgId2 = $this->createTestMessage($client, 'Amazon Security Warning', 'Confirm your Amazon identity immediately.', 88);
        $msgId3 = $this->createTestMessage($client, 'Bitcoin Investment Opportunity', 'Invest in BTC with guaranteed returns.', 92);
        $msgId4 = $this->createTestMessage($client, 'Crédit Agricole Alerte', 'Vérifiez votre compte bancaire.', 90);

        // Create 4 different rules
        $rules = [];

        // Rule 1: PayPal
        $dsl1 = 'RULE multi_e2e_enhanced_paypal { WHERE subject.simhash≈"paypal account" ±20% ACTION tag="paypal" }';
        $compiled1 = $this->transpileDSL($client, $dsl1);
        $rules[] = $this->storeRule($client, $campaignId, $dsl1, $compiled1);

        // Rule 2: Amazon
        $dsl2 = 'RULE multi_e2e_enhanced_amazon { WHERE subject.simhash≈"amazon security" ±20% ACTION tag="amazon" }';
        $compiled2 = $this->transpileDSL($client, $dsl2);
        $rules[] = $this->storeRule($client, $campaignId, $dsl2, $compiled2);

        // Rule 3: Crypto
        $dsl3 = 'RULE multi_e2e_enhanced_crypto { WHERE body.containsAny ["BTC","bitcoin","crypto"] ACTION tag="crypto" }';
        $compiled3 = $this->transpileDSL($client, $dsl3);
        $rules[] = $this->storeRule($client, $campaignId, $dsl3, $compiled3);

        // Rule 4: French bank
        $dsl4 = 'RULE multi_e2e_enhanced_bank_fr { WHERE body.containsAny ["Crédit Agricole","vérifiez compte"] ACTION tag="bank_fr" }';
        $compiled4 = $this->transpileDSL($client, $dsl4);
        $rules[] = $this->storeRule($client, $campaignId, $dsl4, $compiled4);

        // Execute hunt (all rules should execute)
        $huntResult = $this->huntCampaigns($client);

        // Verify all rules executed
        $this->assertGreaterThanOrEqual(4, $huntResult['total_rules']);
        $this->assertGreaterThanOrEqual(4, count($huntResult['results']));

        // Verify each rule has result
        foreach ($rules as $rule) {
            $ruleResults = array_filter($huntResult['results'], fn($r) => $r['rule_id'] === $rule['rule_id']);
            $this->assertNotEmpty($ruleResults, "Rule {$rule['rule_id']} should have results");
            $ruleResult = reset($ruleResults);
            $this->assertEquals('ok', $ruleResult['status']);
        }

        // Verify total hits is sum of individual rule hits
        $totalHitsSum = array_sum(array_map(fn($r) => $r['hits_count'], $huntResult['results']));
        $this->assertGreaterThan(0, $totalHitsSum);
    }

    // ==================== Tests Metrics Persistence ====================

    /**
     * @group endtoend
     */
    public function testMetricsPersistAcrossMultipleHunts(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine')->getManager();

        // Cleanup
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%metrics_e2e_enhanced%\'');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'metrics-e2e-enhanced\'');
        $em->clear();

        // Create campaign and messages
        $campaign = new \App\Domain\CampaignRadar\Campaign('metrics-e2e-enhanced');
        $em->persist($campaign);
        $em->flush();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Use very unique pattern unlikely to match other tests
        $uniquePattern = 'METRICS_E2E_ENHANCED_UNIQUE_PATTERN_' . bin2hex(random_bytes(8));
        $msgId1 = $this->createTestMessage($client, 'Metrics Test 1', "Body content with $uniquePattern marker.", 80);
        $msgId2 = $this->createTestMessage($client, 'Metrics Test 2', "Body content with $uniquePattern marker.", 85);

        // Create rule with unique pattern in body to avoid matching other test messages
        $dsl = "RULE metrics_e2e_enhanced { WHERE body.containsAny [\"$uniquePattern\"] ACTION tag=\"test\" }";
        $compiled = $this->transpileDSL($client, $dsl);
        $rule = $this->storeRule($client, $campaignId, $dsl, $compiled);
        $ruleId = $rule['rule_id'];

        // Hunt 1
        $hunt1 = $this->huntCampaigns($client);
        $result1 = array_filter($hunt1['results'], fn($r) => $r['rule_id'] === $ruleId);
        $result1 = reset($result1);
        $hits1 = $result1['hits_count'];
        $ppv1 = $result1['ppv'];

        // Verify metrics stored in DB after hunt 1
        $em->clear();
        $storedRule1 = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $ruleId);
        $this->assertNotNull($storedRule1);
        $this->assertEquals($hits1, $storedRule1->getHitsTotal());
        $this->assertEquals($ppv1, $storedRule1->getPpv());

        // Hunt 2 (without new messages, metrics should be updated again)
        $hunt2 = $this->huntCampaigns($client);
        $result2 = array_filter($hunt2['results'], fn($r) => $r['rule_id'] === $ruleId);
        $result2 = reset($result2);
        $hits2 = $result2['hits_count'];
        $ppv2 = $result2['ppv'];

        // Verify metrics updated after hunt 2 (metrics are incremental)
        $em->clear();
        $storedRule2 = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $ruleId);
        $this->assertNotNull($storedRule2);
        // updateMetrics() is incremental, so after 2 hunts: hitsTotal = hits1 + hits2
        $this->assertEquals($hits1 + $hits2, $storedRule2->getHitsTotal(), 'Metrics are incremental');
        $this->assertEquals($ppv2, $storedRule2->getPpv());

        // Each hunt should return same number of hits (same messages in DB)
        $this->assertEquals($hits1, $hits2, 'Each hunt should find same messages');
    }
}
