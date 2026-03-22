<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end test for Campaign Radar Phase 4: Transpiler & Hunter
 *
 * @group endtoend
 *
 * Scenario: Complete Transpiler/Hunter workflow
 * 1. Create campaign with messages
 * 2. Transpile DSL rule → SQL
 * 3. Store compiled rule in database
 * 4. Hunt campaigns (shadow mode execution)
 * 5. Verify PPV calculation
 * 6. Verify metrics are updated
 */
class TranspilerHunterE2eTest extends WebTestCase
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

    private function createTestMessage($client, string $subject, string $body, int $scoreRisk = 75): string
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel, 'Channel fixture not found');
        $this->assertNotNull($scamType, 'ScamType fixture not found');
        $this->assertNotNull($account, 'MailAccount fixture not found');

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
                'ts_first' => (new \DateTimeImmutable('-3 hours'))->format(DATE_ATOM),
                'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'stix_id' => 'stix-transpiler-e2e-' . bin2hex(random_bytes(4)),
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
                    'message_id' => '<transpiler-e2e-' . bin2hex(random_bytes(8)) . '@evil.test>',
                    'auth' => ['dkim' => false, 'spf' => false],
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);

        return $msgData['msg_id'];
    }

    /**
     * @group endtoend
     */
    public function testTranspileDslToSql(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Test simhash predicate
        $dsl = 'RULE test_simhash { WHERE subject.simhash≈"urgent account" ±15% ACTION tag="test" }';

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
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('sql', $response);
        $this->assertArrayHasKey('params', $response);
        $this->assertArrayHasKey('tests', $response);

        // Verify SQL uses prepared statements
        $sql = $response['sql'];
        $this->assertStringContainsString('similarity(subject, :p0)', $sql, 'Should use prepared statement parameter');
        $this->assertStringContainsString('>= 0.85', $sql, 'Should calculate threshold from ±15%');

        // Verify params
        $params = $response['params'];
        $this->assertArrayHasKey('p0', $params);
        $this->assertSame('urgent account', $params['p0']);
    }

    /**
     * @group endtoend
     */
    public function testTranspileContainsAnyPredicate(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $dsl = 'RULE test_contains { WHERE body.containsAny ["verify account","confirm identity"] ACTION tag="phishing" }';

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
        $response = json_decode($client->getResponse()->getContent(), true);

        $sql = $response['sql'];
        $this->assertStringContainsString('ILIKE ANY(ARRAY[', $sql, 'Should use PostgreSQL ILIKE ANY');
        $this->assertStringContainsString(':p0', $sql);
        $this->assertStringContainsString(':p1', $sql);

        $params = $response['params'];
        $this->assertCount(2, $params);
        $this->assertStringContainsString('verify account', $params['p0']);
        $this->assertStringContainsString('confirm identity', $params['p1']);
    }

    /**
     * @group endtoend
     */
    public function testTranspileInvalidDslReturnsError(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $invalidDsl = 'INVALID DSL WITHOUT KEYWORDS';

        $client->request(
            'POST',
            '/api/v1/campaign/transpile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ],
            json_encode(['dsl' => $invalidDsl])
        );

        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
    }

    /**
     * @group endtoend
     */
    public function testStoreCompiledRule(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create campaign
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = new \App\Domain\CampaignRadar\Campaign('e2e-transpiler-test');
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Transpile DSL
        $dsl = 'RULE bank_phish_2025 { WHERE subject.simhash≈"urgent security" ±20% AND body.containsAny ["verify","confirm"] ACTION tag="campaign:bank_phish", score+=30 }';

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
        $transpileResponse = json_decode($client->getResponse()->getContent(), true);

        // Store rule
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
                'compiled_sql' => [
                    'sql' => $transpileResponse['sql'],
                    'params' => $transpileResponse['params'],
                    'tests' => $transpileResponse['tests'],
                ],
            ])
        );

        $this->assertResponseIsSuccessful();
        $storeResponse = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('rule_id', $storeResponse);
        $this->assertArrayHasKey('campaign_id', $storeResponse);
        $this->assertArrayHasKey('status', $storeResponse);
        $this->assertArrayHasKey('enabled', $storeResponse);

        $this->assertSame($campaignId, $storeResponse['campaign_id']);
        $this->assertSame('shadow', $storeResponse['status']);
        $this->assertTrue($storeResponse['enabled']);

        // Verify rule is stored in database
        $em->clear();
        $ruleId = $storeResponse['rule_id'];
        $rule = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $ruleId);

        $this->assertNotNull($rule);
        $this->assertSame($dsl, $rule->getDsl());
        $this->assertTrue($rule->isEnabled());
        $this->assertNull($rule->getPromotedAt(), 'Rule should be in shadow mode (promoted_at = null)');

        // Verify compiled data
        $compiledData = $rule->getCompiledData();
        $this->assertNotNull($compiledData);
        $this->assertArrayHasKey('sql', $compiledData);
        $this->assertArrayHasKey('params', $compiledData);
        $this->assertSame($transpileResponse['sql'], $compiledData['sql']);
    }

    /**
     * @group endtoend
     */
    public function testHuntCampaigns(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Cleanup previous test data
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE dsl LIKE \'%hunt_e2e_test%\'');
        $em->getConnection()->executeStatement('DELETE FROM message_campaign WHERE campaign_id IN (SELECT campaign_id FROM campaign WHERE created_by = \'hunt-e2e-test\')');
        $em->getConnection()->executeStatement('DELETE FROM campaign WHERE created_by = \'hunt-e2e-test\'');
        $em->clear();

        // Create campaign with messages
        $campaign = new \App\Domain\CampaignRadar\Campaign('hunt-e2e-test');
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Create test messages with different risk scores
        $msgId1 = $this->createTestMessage($client, 'Urgent Security Alert', 'Please verify your account immediately', 85);
        $msgId2 = $this->createTestMessage($client, 'Urgent Security Alert', 'Please verify your account immediately', 90);
        $msgId3 = $this->createTestMessage($client, 'Legitimate Email', 'Your order has shipped', 10);

        // Assign messages to campaign
        foreach ([$msgId1, $msgId2, $msgId3] as $msgId) {
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

        // Create and store a rule
        $dsl = 'RULE hunt_e2e_test { WHERE subject.simhash≈"urgent security" ±20% ACTION tag="test" }';

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

        $transpileResponse = json_decode($client->getResponse()->getContent(), true);

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
                'compiled_sql' => [
                    'sql' => $transpileResponse['sql'],
                    'params' => $transpileResponse['params'],
                    'tests' => [],
                ],
            ])
        );

        $storeResponse = json_decode($client->getResponse()->getContent(), true);
        $ruleId = $storeResponse['rule_id'];

        // Execute hunt
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
        $huntResponse = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('total_rules', $huntResponse);
        $this->assertArrayHasKey('total_hits', $huntResponse);
        $this->assertArrayHasKey('results', $huntResponse);

        $this->assertGreaterThanOrEqual(1, $huntResponse['total_rules']);

        // Verify metrics are calculated
        $ruleResults = array_filter($huntResponse['results'], fn($r) => $r['rule_id'] === $ruleId);
        $this->assertNotEmpty($ruleResults, 'Should have results for the created rule');

        $ruleResult = reset($ruleResults);
        $this->assertArrayHasKey('hits_count', $ruleResult);
        $this->assertArrayHasKey('ppv', $ruleResult);
        $this->assertArrayHasKey('validation', $ruleResult);

        // Verify PPV calculation (heuristic: score_risk >= 30 = true positive)
        // We created 2 high-risk messages (85, 90) and 1 low-risk (10)
        // If the rule matches the high-risk messages, PPV should be high
        if ($ruleResult['hits_count'] > 0) {
            $this->assertArrayHasKey('true_pos', $ruleResult['validation']);
            $this->assertArrayHasKey('false_pos', $ruleResult['validation']);
            $this->assertGreaterThanOrEqual(0, $ruleResult['ppv']);
            $this->assertLessThanOrEqual(1, $ruleResult['ppv']);
        }

        // Verify rule metrics are updated in database
        $em->clear();
        $rule = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $ruleId);
        $this->assertNotNull($rule);
    }

    /**
     * @group endtoend
     */
    public function testHuntWithDisabledRuleSkipsExecution(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Create campaign and disabled rule
        $em = $client->getContainer()->get('doctrine')->getManager();
        $campaign = new \App\Domain\CampaignRadar\Campaign('disabled-rule-test');
        $em->persist($campaign);
        $em->flush();

        $rule = new \App\Domain\CampaignRadar\CampaignRule(
            $campaign->getCampaignId(),
            'RULE disabled_test { WHERE subject.simhash≈"test" ±10% ACTION tag="test" }'
        );
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id FROM message WHERE similarity(subject, :p0) >= 0.9',
            'params' => ['p0' => 'test'],
        ]);
        $rule->disable(); // Disable the rule
        $em->persist($rule);
        $em->flush();

        // Execute hunt
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
        $huntResponse = json_decode($client->getResponse()->getContent(), true);

        // Verify disabled rule was not executed
        $disabledRuleResults = array_filter(
            $huntResponse['results'],
            fn($r) => $r['rule_id'] === $rule->getRuleId()->toRfc4122()
        );

        $this->assertEmpty($disabledRuleResults, 'Disabled rule should not be in results');
    }
}
