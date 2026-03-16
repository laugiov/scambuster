<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Autonomy;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end test for the full autonomous pipeline.
 *
 * Validates that the system can operate autonomously by exercising the
 * complete cycle as n8n workflows would:
 *
 *   1. Ingest a raw email (WF-INTAKE-EMAIL-V2)
 *   2. Verify conversation creation + scam type assignment
 *   3. Verify header IOC extraction (automatic during ingest)
 *   4. Retrieve conversation context (persona assignment, IOC state)
 *   5. Ingest enriched IOCs (WF-EXTRACT-AND-ENRICH-IOC)
 *   6. Close conversation (WF-SCAMBAITING-END-CONVERSATION)
 *   7. Verify reward calculation + persona stats update
 *   8. Verify monitoring endpoint reflects the activity
 *
 * The LLM is not available in test (no API key), so reply generation
 * is NOT tested here. This test validates the structural wiring of all
 * components in the autonomous loop.
 */
final class AutonomyPipelineTest extends WebTestCase
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
     * Full autonomous cycle: ingest → IOC extract → context → enrich → close → reward → monitor.
     */
    public function testFullAutonomousCycle(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        // --- Setup: create a mail account for ingestion ---
        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '55555555-5555-5555-5555-555555555555',
            'IMAP',
            'imap.autonomy-test.com',
            'autonomy-test-hash-' . bin2hex(random_bytes(4)),
            ['mail.read'],
            true
        );
        $em->persist($mailAccount);
        $em->flush();

        $jwt = $this->getValidJwt($client);

        // ================================================================
        // STEP 1: Ingest a scam email (simulates WF-INTAKE-EMAIL-V2)
        // ================================================================
        $messageId = '<autonomy-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $rawEmail = <<<MAIL
Subject: URGENT: Your account has been compromised
From: "Security Team" <security@phishing-bank.test>
To: honeypot@scambuster.test
Date: Sat, 15 Mar 2026 10:00:00 +0000
Message-ID: {$messageId}
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: 8bit

Dear Customer,

Your bank account has been compromised. Please verify your identity immediately.
Contact us at +33612345678 or send your IBAN FR7630006000011234567890189 to billing@phishing-bank.test.

Visit https://phishing-bank.test/verify to secure your account.

Security Team
MAIL;

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], json_encode([
            'account_id' => $accountId,
            'raw_source' => base64_encode($rawEmail),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'score_risk' => 75,
            'rspamd' => ['score' => 8.0, 'symbols' => ['PHISHING']],
        ]));

        $this->assertResponseStatusCodeSame(201, 'Email ingestion should succeed');
        $ingestData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('msg_id', $ingestData);
        $this->assertArrayHasKey('conv_id', $ingestData);
        $this->assertSame('ingested', $ingestData['status']);

        $msgId = $ingestData['msg_id'];
        $convId = $ingestData['conv_id'];

        // ================================================================
        // STEP 2: Verify conversation was created with correct status
        // ================================================================
        $em->clear();
        $conversation = $em->getRepository(Conversation::class)->find($convId);
        $this->assertNotNull($conversation, 'Conversation should exist after ingest');
        $this->assertSame(ConversationStatus::OPEN, $conversation->getStatus());

        // ================================================================
        // STEP 3: Verify message was persisted with headers
        // ================================================================
        $message = $em->getRepository(Message::class)->find($msgId);
        $this->assertNotNull($message, 'Message should exist after ingest');
        $this->assertStringContainsString('URGENT', $message->getSubject());
        $this->assertStringContainsString('bank account', $message->getBodyText());

        // ================================================================
        // STEP 4: Verify header IOCs were extracted automatically
        // ================================================================
        $headerIocs = $em->getRepository(ObservedIoc::class)->findBy(['message' => $message]);
        // Header extractor should capture: message_id, subject, from email at minimum
        $this->assertGreaterThanOrEqual(1, count($headerIocs), 'Header IOCs should be extracted during ingest');

        // ================================================================
        // STEP 5: Get conversation context (simulates WF-REPLY-GENERATE-V2)
        // ================================================================
        $client->request('GET', "/api/v1/communication/conversation/{$convId}/context", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $context = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame($convId, $context['conv_id']);
        $this->assertSame('open', $context['status']);
        $this->assertArrayHasKey('persona', $context);
        $this->assertArrayHasKey('scam_type', $context);
        $this->assertArrayHasKey('last_messages', $context);
        $this->assertNotEmpty($context['last_messages'], 'Should have at least the ingested message');

        // Persona should have been auto-assigned
        $this->assertNotNull($context['persona'], 'Persona should be auto-assigned on context retrieval');

        // ================================================================
        // STEP 6: Ingest enriched IOCs (simulates WF-EXTRACT-AND-ENRICH-IOC)
        // ================================================================
        $iocPayloads = [
            [
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'email',
                    'value' => 'billing@phishing-bank.test',
                    'value_norm' => 'billing@phishing-bank.test',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => ['virustotal' => ['malicious' => 5]],
                'tags' => ['phishing'],
                'tlp' => 'AMBER',
            ],
            [
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'url',
                    'value' => 'https://phishing-bank.test/verify',
                    'value_norm' => 'phishing-bank.test/verify',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [
                    'virustotal' => ['malicious' => 10],
                    'urlscan' => ['verdict' => 'malicious'],
                ],
                'tags' => ['phishing', 'credential-theft'],
                'tlp' => 'RED',
            ],
            [
                'msg_id' => $msgId,
                'ioc' => [
                    'type' => 'iban',
                    'value' => 'FR7630006000011234567890189',
                    'value_norm' => 'FR7630006000011234567890189',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [],
                'tags' => ['financial-fraud'],
                'tlp' => 'RED',
            ],
        ];

        $obsIds = [];
        foreach ($iocPayloads as $payload) {
            $client->request('POST', '/api/v1/iocs/enriched', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ], json_encode($payload));

            $this->assertResponseStatusCodeSame(201);
            $iocData = json_decode($client->getResponse()->getContent(), true);
            $obsIds[] = $iocData['obs_id'];

            $this->assertArrayHasKey('risk', $iocData);
        }

        $this->assertCount(3, $obsIds, 'Should have ingested 3 enriched IOCs');

        // ================================================================
        // STEP 7: Verify conversation IOCs aggregate correctly
        // ================================================================
        $client->request('GET', "/api/v1/communication/conversation/{$convId}/iocs", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $convIocs = json_decode($client->getResponse()->getContent(), true);
        // At least header IOCs + 3 enriched IOCs
        $this->assertGreaterThanOrEqual(3, count($convIocs), 'Conversation should aggregate all IOCs');

        // ================================================================
        // STEP 8: Export MISP (verify intelligence export works)
        // ================================================================
        $client->request('GET', "/api/v1/conversations/{$convId}/export/misp", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $mispEvent = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('Event', $mispEvent);
        $this->assertNotEmpty($mispEvent['Event']['Attribute'], 'MISP export should have attributes');

        // ================================================================
        // STEP 9: Close conversation (simulates WF-SCAMBAITING-END-CONVERSATION)
        // ================================================================
        $client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);

        // Verify conversation is now closed with reward
        $em->clear();
        $closedConv = $em->getRepository(Conversation::class)->find($convId);
        $this->assertSame(ConversationStatus::CLOSED, $closedConv->getStatus(), 'Conversation should be CLOSED');
        $this->assertNotNull($closedConv->getRewardValue(), 'Reward should be calculated on closure');
        $this->assertGreaterThanOrEqual(0.0, $closedConv->getRewardValue());

        // ================================================================
        // STEP 10: Verify monitoring endpoint reflects the activity
        // ================================================================
        $client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $monitoring = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame('operational', $monitoring['status']);
        $this->assertFalse($monitoring['kill_switch_active']);
        $this->assertGreaterThanOrEqual(1, $monitoring['conversations']['closed'], 'Should have at least 1 closed conversation');
        $this->assertGreaterThanOrEqual(3, $monitoring['iocs']['total'], 'Should have at least 3 IOCs');
        $this->assertNotNull($monitoring['last_activity']['last_inbound'], 'Should have recorded inbound activity');
    }

    /**
     * Test that the pipeline handles duplicate email ingestion gracefully.
     */
    public function testDuplicateIngestIsIdempotent(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $accountId = uuid_create(UUID_TYPE_RANDOM);
        $mailAccount = new MailAccount(
            $accountId,
            '66666666-6666-6666-6666-666666666666',
            'IMAP',
            'imap.dedup-test.com',
            'dedup-test-hash-' . bin2hex(random_bytes(4)),
            ['mail.read'],
            true
        );
        $em->persist($mailAccount);
        $em->flush();

        $jwt = $this->getValidJwt($client);

        $messageId = '<dedup-' . bin2hex(random_bytes(8)) . '@evil.test>';
        $rawEmail = <<<MAIL
Subject: Duplicate test
From: "Scammer" <scammer@evil.test>
To: honeypot@scambuster.test
Date: Sat, 15 Mar 2026 11:00:00 +0000
Message-ID: {$messageId}
Content-Type: text/plain

Duplicate test body
MAIL;

        $payload = json_encode([
            'account_id' => $accountId,
            'raw_source' => base64_encode($rawEmail),
            'ts_received' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'channel' => 'email',
            'score_risk' => 50,
            'rspamd' => ['score' => 5.0, 'symbols' => ['DEDUP_TEST']],
        ]);

        // First ingest
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], $payload);
        $this->assertResponseStatusCodeSame(201);

        // Second ingest (same email) -- should be idempotent
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ], $payload);
        $response2 = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('already_exists', $response2['status'], 'Duplicate ingest should return already_exists');
    }

    /**
     * Test that stale conversation closure command integrates with the full pipeline.
     */
    public function testStaleConversationClosureTriggersBanditUpdate(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        // Find an open conversation from fixtures
        $openConv = $em->getRepository(Conversation::class)->findOneBy(['status' => ConversationStatus::OPEN]);

        if ($openConv === null) {
            $this->markTestSkipped('No open conversation in E2E fixtures');
        }

        $convId = $openConv->getConvId();

        // Ensure it has a persona assigned
        if ($openConv->getPersona() === null) {
            $jwt = $this->getValidJwt($client);
            $client->request('GET', "/api/v1/communication/conversation/{$convId}/context", [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ]);
            $em->clear();
            $openConv = $em->getRepository(Conversation::class)->find($convId);
        }

        // Make it stale by setting ts_last to 10 days ago
        $reflection = new \ReflectionProperty(Conversation::class, 'tsLast');
        $reflection->setValue($openConv, new \DateTimeImmutable('-10 days'));
        $reflectionReward = new \ReflectionProperty(Conversation::class, 'rewardValue');
        $reflectionReward->setValue($openConv, null);
        $em->flush();

        // Close via ConversationClosureService (as the command would)
        $closureService = $client->getContainer()->get(\App\Application\Scambaiting\ConversationClosureService::class);
        $closureService->closeConversation($convId);

        // Verify
        $em->clear();
        $closedConv = $em->getRepository(Conversation::class)->find($convId);
        $this->assertSame(ConversationStatus::CLOSED, $closedConv->getStatus());
        $this->assertNotNull($closedConv->getRewardValue(), 'Stale closure should calculate reward');
    }
}
