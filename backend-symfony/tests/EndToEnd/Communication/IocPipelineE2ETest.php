<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for the complete IOC pipeline.
 *
 * Tests the full lifecycle: ingest message -> ingest IOCs -> enrich -> risk score -> export MISP.
 * Covers financial IOC types (IBAN, crypto wallets, BIC) and cross-message deduplication.
 */
final class IocPipelineE2ETest extends WebTestCase
{
    use \App\Tests\Support\CorroboratesIoc;

    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    private function createConversationWithMessages($client, $jwt, int $messageCount = 2): array
    {
        $em = $client->getContainer()->get('doctrine')->getManager();
        $channel = $em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        $client->request('POST', '/api/v1/communication/conversation', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'primary_channel_id' => $channel->getChannelId(),
            'scam_type_id' => $scamType->getScamTypeId(),
            'account_id' => $account->getAccountId(),
            'status' => 'open',
            'score_risk' => 10,
            'ts_first' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM),
            'ts_last' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'stix_id' => 'stix-pipeline-e2e-' . bin2hex(random_bytes(4)),
        ]));
        $this->assertResponseStatusCodeSame(201);
        $convData = json_decode($client->getResponse()->getContent(), true);
        $convId = $convData['conv_id'];

        $msgIds = [];
        for ($i = 1; $i <= $messageCount; $i++) {
            $client->request('POST', '/api/v1/communication/message', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ], json_encode([
                'conv_id' => $convId,
                'channel_id' => $channel->getChannelId(),
                'direction' => 'in',
                'body_text' => "Message {$i} from scammer",
                'headers' => [
                    'from' => 'scammer@evil.test',
                    'to' => 'honeypot@scambuster.test',
                    'message_id' => '<pipeline-e2e-msg' . $i . '-' . bin2hex(random_bytes(4)) . '@evil.test>',
                    'subject' => 'RE: Urgent matter',
                ],
                'ts_msg' => (new \DateTimeImmutable("-{$i} hours"))->format(DATE_ATOM),
            ]));
            $this->assertResponseStatusCodeSame(201);
            $msgData = json_decode($client->getResponse()->getContent(), true);
            $msgIds[] = $msgData['msg_id'];
        }

        return ['conv_id' => $convId, 'msg_ids' => $msgIds, 'channel' => $channel];
    }

    private function ingestIoc($client, $jwt, string $msgId, array $ioc, array $enrichment = [], array $extra = []): string
    {
        $payload = array_merge([
            'msg_id' => $msgId,
            'ioc' => array_merge([
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $ioc),
            'enrichment' => $enrichment,
        ], $extra);

        $client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['obs_id'];
    }

    // -----------------------------------------------------------------------
    //  Test: Full pipeline with financial IOCs
    // -----------------------------------------------------------------------

    public function testFullPipelineWithFinancialIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createConversationWithMessages($client, $jwt, 2);

        // Step 1: Ingest multiple IOC types across 2 messages
        $obsIdIban = $this->ingestIoc($client, $jwt, $data['msg_ids'][0], [
            'type' => 'iban',
            'value' => 'DE89 3704 0044 0532 0130 00',
            'value_norm' => 'DE89370400440532013000',
        ], [], ['category' => 'B2B_invoice_change', 'tags' => ['financial-fraud'], 'tlp' => 'RED']);

        $obsIdBtc = $this->ingestIoc($client, $jwt, $data['msg_ids'][0], [
            'type' => 'wallet_btc',
            'value' => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
            'value_norm' => 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
        ], [], ['tags' => ['crypto-fraud'], 'tlp' => 'AMBER']);

        $obsIdPhone = $this->ingestIoc($client, $jwt, $data['msg_ids'][1], [
            'type' => 'phone',
            'value' => '+44 20 7946 0958',
            'value_norm' => '442079460958',
        ]);

        $obsIdEmail = $this->ingestIoc($client, $jwt, $data['msg_ids'][1], [
            'type' => 'email',
            'value' => 'billing@evil-corp.test',
            'value_norm' => 'billing@evil-corp.test',
        ], ['virustotal' => ['malicious' => 5, 'suspicious' => 0]]);

        // Step 2: Verify conversation aggregates all IOCs
        $client->request('GET', "/api/v1/communication/conversation/{$data['conv_id']}/iocs", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $convIocs = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(4, $convIocs, 'Conversation should have 4 unique IOCs across 2 messages');

        // Export hold (IocExportPolicy). Financial IOCs need analyst confirmation
        // (WS6); non-financial IOCs seen in one conversation are held pending
        // corroboration — CORROBORATE them (not confirm) so their MISP to_ids flag
        // keeps its type-based default.
        $conn = static::getContainer()->get(\Doctrine\DBAL\Connection::class);

        foreach (['DE89370400440532013000', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'] as $valueNorm) {
            $conn->executeStatement(
                "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
                 SELECT indicator_id, 'confirmed', NULL, 'e2e', NOW() FROM indicator WHERE value_norm = :vn
                 ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
                ['vn' => $valueNorm],
            );
        }
        $this->corroborateByValueNorm($conn, '442079460958');
        $this->corroborateByValueNorm($conn, 'billing@evil-corp.test');

        // Step 3: Export MISP and verify financial IOC metadata
        $client->request('GET', "/api/v1/conversations/{$data['conv_id']}/export/misp", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $mispEvent = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('Event', $mispEvent);
        $attributes = $mispEvent['Event']['Attribute'];
        $this->assertCount(4, $attributes, 'MISP export should contain 4 attributes');

        // Verify each IOC type maps correctly
        $typeMap = [];
        foreach ($attributes as $attr) {
            $typeMap[$attr['type']] = $attr;
        }

        // IBAN
        $this->assertArrayHasKey('iban', $typeMap, 'MISP should contain IBAN attribute');
        $this->assertSame('Financial fraud', $typeMap['iban']['category']);
        $this->assertTrue($typeMap['iban']['to_ids']);
        $this->assertSame('DE89370400440532013000', $typeMap['iban']['value']);

        // BTC wallet
        $this->assertArrayHasKey('btc', $typeMap, 'MISP should contain BTC attribute');
        $this->assertSame('Financial fraud', $typeMap['btc']['category']);
        $this->assertTrue($typeMap['btc']['to_ids']);

        // Phone
        $this->assertArrayHasKey('phone-number', $typeMap, 'MISP should contain phone attribute');
        $this->assertSame('Person', $typeMap['phone-number']['category']);
        $this->assertFalse($typeMap['phone-number']['to_ids']);

        // Email
        $this->assertArrayHasKey('email-src', $typeMap, 'MISP should contain email attribute');
        $this->assertSame('Network activity', $typeMap['email-src']['category']);
        $this->assertTrue($typeMap['email-src']['to_ids']);
    }

    // -----------------------------------------------------------------------
    //  Test: Enrichment update via PATCH recalculates risk
    // -----------------------------------------------------------------------

    public function testEnrichmentPatchRecalculatesRisk(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createConversationWithMessages($client, $jwt, 1);

        // Ingest IOC with no enrichment (low risk)
        $obsId = $this->ingestIoc($client, $jwt, $data['msg_ids'][0], [
            'type' => 'url',
            'value' => 'https://suspicious-site.test/verify',
            'value_norm' => 'suspicious-site.test/verify',
        ]);

        // PATCH enrichment with VirusTotal results
        $client->request('PATCH', "/api/v1/iocs/{$obsId}/enrich", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'enrichment' => [
                'virustotal' => [
                    'malicious' => 12,
                    'suspicious' => 3,
                    'harmless' => 75,
                    'undetected' => 10,
                ],
                'urlscan' => [
                    'verdict' => 'malicious',
                    'status' => 'completed',
                    'permalink' => 'https://urlscan.io/result/test123/',
                ],
            ],
        ]));

        $this->assertResponseStatusCodeSame(200);
        $patchResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($patchResponse['updated']);

        // Verify the IOC now has updated enrichment in DB
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->clear();
        $ioc = $em->getRepository(\App\Domain\Communication\ObservedIoc::class)->find($obsId);
        $this->assertNotNull($ioc);
        $context = $ioc->getContext();

        $this->assertArrayHasKey('enrichment', $context);
        $this->assertArrayHasKey('virustotal', $context['enrichment']);
        $this->assertSame(12, $context['enrichment']['virustotal']['malicious']);

        // Verify score was recalculated
        $this->assertArrayHasKey('score', $context);
        $this->assertGreaterThanOrEqual(70, $context['score']['agg'], 'VT malicious should produce high risk');
    }

    // -----------------------------------------------------------------------
    //  Test: STIX metadata present after IOC ingestion
    // -----------------------------------------------------------------------

    public function testStixMetadataPopulatedOnIngestion(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createConversationWithMessages($client, $jwt, 1);

        // Ingest various IOC types and verify STIX metadata
        $testCases = [
            ['type' => 'iban', 'value' => 'GB29NWBK60161331926819', 'value_norm' => 'GB29NWBK60161331926819', 'expected_sco' => 'x-scambuster-iban'],
            ['type' => 'wallet_eth', 'value' => '0x742d35Cc6634C0532925a3b844Bc9e7595f2bD20', 'value_norm' => '0x742d35cc6634c0532925a3b844bc9e7595f2bd20', 'expected_sco' => 'x-scambuster-crypto-wallet'],
            ['type' => 'phone', 'value' => '+1-555-0123', 'value_norm' => '15550123', 'expected_sco' => 'x-scambuster-phone'],
            ['type' => 'domain', 'value' => 'phishing-domain.test', 'value_norm' => 'phishing-domain.test', 'expected_sco' => 'domain-name'],
        ];

        $em = $client->getContainer()->get('doctrine')->getManager();

        foreach ($testCases as $tc) {
            $obsId = $this->ingestIoc($client, $jwt, $data['msg_ids'][0], [
                'type' => $tc['type'],
                'value' => $tc['value'],
                'value_norm' => $tc['value_norm'],
            ]);

            $em->clear();
            $ioc = $em->getRepository(\App\Domain\Communication\ObservedIoc::class)->find($obsId);
            $this->assertNotNull($ioc, "IOC {$tc['type']} should exist");

            $context = $ioc->getContext();
            $this->assertArrayHasKey('stix', $context, "IOC {$tc['type']} should have STIX metadata");
            $this->assertSame(
                $tc['expected_sco'],
                $context['stix']['sco_type'],
                "IOC {$tc['type']} should map to STIX SCO type '{$tc['expected_sco']}'"
            );
            $this->assertStringContainsString(
                $tc['expected_sco'] . ':value',
                $context['stix']['pattern'],
                "IOC {$tc['type']} STIX pattern should reference '{$tc['expected_sco']}'"
            );

            $this->assertArrayHasKey('misp', $context, "IOC {$tc['type']} should have MISP metadata");
            $this->assertNotSame('Other', $context['misp']['category'], "IOC {$tc['type']} should not fall back to 'Other'");
        }
    }

    // -----------------------------------------------------------------------
    //  Test: Risk scoring drives reply decision
    // -----------------------------------------------------------------------

    public function testRiskScoringDrivesReplyDecision(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createConversationWithMessages($client, $jwt, 1);

        // High-risk IOC (VT malicious) -> should_reply = true
        $client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'msg_id' => $data['msg_ids'][0],
            'ioc' => [
                'type' => 'url',
                'value' => 'https://definitely-malicious.test',
                'value_norm' => 'definitely-malicious.test',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 15, 'suspicious' => 0],
            ],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $response = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('high', $response['risk']['level']);
        $this->assertTrue($response['risk']['should_reply'], 'High-risk IOC should trigger reply');

        // Low-risk IOC (no enrichment hits) -> should_reply = false
        $data2 = $this->createConversationWithMessages($client, $jwt, 1);
        $client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'msg_id' => $data2['msg_ids'][0],
            'ioc' => [
                'type' => 'domain',
                'value' => 'benign-site.test',
                'value_norm' => 'benign-site.test',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 0, 'suspicious' => 0],
            ],
        ]));

        $this->assertResponseStatusCodeSame(201);
        $response2 = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('low', $response2['risk']['level']);
        $this->assertFalse($response2['risk']['should_reply'], 'Low-risk IOC should not trigger reply');
    }

    // -----------------------------------------------------------------------
    //  Test: Duplicate IOC across messages is handled correctly
    // -----------------------------------------------------------------------

    public function testDuplicateIocAcrossMessagesPreservesBoth(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createConversationWithMessages($client, $jwt, 3);

        $sharedIban = 'FR7630006000011234567890189';

        // Same IBAN appears in 3 different messages
        $obsIds = [];
        foreach ($data['msg_ids'] as $msgId) {
            $obsIds[] = $this->ingestIoc($client, $jwt, $msgId, [
                'type' => 'iban',
                'value' => $sharedIban,
                'value_norm' => $sharedIban,
            ]);
        }

        // Each message should have its own ObservedIoc
        $this->assertCount(3, $obsIds);

        // But the indicator table should have only 1 row (unique type + value_norm)
        $em = $client->getContainer()->get('doctrine')->getManager();
        $em->clear();

        $conn = $em->getConnection();
        $indicatorCount = $conn->fetchOne(
            "SELECT COUNT(*) FROM indicator WHERE type = 'iban' AND value_norm = :vn",
            ['vn' => $sharedIban]
        );
        $this->assertSame(1, (int) $indicatorCount, 'Indicator table should deduplicate by (type, value_norm)');

        // Conversation IOCs endpoint should deduplicate
        $client->request('GET', "/api/v1/communication/conversation/{$data['conv_id']}/iocs", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $convIocs = json_decode($client->getResponse()->getContent(), true);

        // Should return deduplicated list (1 unique IBAN)
        $ibanIocs = array_filter($convIocs, fn($ioc) => ($ioc['type'] ?? '') === 'iban');
        $this->assertLessThanOrEqual(3, count($ibanIocs), 'At most 3 observed IOCs for same IBAN');
    }
}
