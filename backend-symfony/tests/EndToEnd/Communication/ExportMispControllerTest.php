<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Communication;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for ExportMispController.
 *
 * Tests MISP Event export from conversation IOCs.
 */
final class ExportMispControllerTest extends WebTestCase
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

    private function createTestConversationWithIocs($client, $jwt): array
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
                'stix_id' => 'stix-misp-export-e2e-' . bin2hex(random_bytes(4)),
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
                'body_text' => 'Pay urgent invoice to IBAN FR7612345678901234567890185. Call +33712345678 or email billing@scam.test',
                'headers' => [
                    'from' => 'scammer@scam.test',
                    'to' => 'victim@example.test',
                    'message_id' => '<misp-export-test-' . bin2hex(random_bytes(8)) . '@scam.test>',
                    'subject' => 'URGENT Payment Required',
                ],
                'ts_msg' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $msgData = json_decode($client->getResponse()->getContent(), true);
        $msgId = $msgData['msg_id'];

        // Set external_message_id
        $message = $em->getRepository(\App\Domain\Communication\Message::class)->find($msgId);
        $externalMessageId = '<misp-export-' . bin2hex(random_bytes(8)) . '@mail.gmail.com>';
        $message->setExternalMessageId($externalMessageId);
        $em->flush();

        // Ingest 3 IOCs (email, phone, iban)
        $iocs = [
            [
                'message_id' => $externalMessageId,
                'ioc' => [
                    'type' => 'email',
                    'value' => 'billing@scam.test',
                    'value_norm' => 'billing@scam.test',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => ['virustotal' => ['malicious' => 2]],
                'category' => 'B2B_invoice_change',
                'tags' => ['phishing', 'bec'],
                'tlp' => 'AMBER',
            ],
            [
                'message_id' => $externalMessageId,
                'ioc' => [
                    'type' => 'phone',
                    'value' => '+33712345678',
                    'value_norm' => '33712345678',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'category' => 'B2B_invoice_change',
                'tags' => ['phishing'],
                'tlp' => 'AMBER',
            ],
            [
                'message_id' => $externalMessageId,
                'ioc' => [
                    'type' => 'iban',
                    'value' => 'FR76 1234 5678 9012 3456 7890 185',
                    'value_norm' => 'FR7612345678901234567890185',
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ],
                'enrichment' => [],
                'category' => 'B2B_invoice_change',
                'tags' => ['financial-fraud'],
                'tlp' => 'RED',
            ],
        ];

        foreach ($iocs as $iocData) {
            $client->request(
                'POST',
                '/api/v1/iocs/enriched',
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
                ],
                json_encode($iocData)
            );
            $this->assertResponseStatusCodeSame(201);
        }

        return ['conv_id' => $convId, 'msg_id' => $msgId];
    }

    public function testExportMispReturnsValidEventStructure(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);
        $data = $this->createTestConversationWithIocs($client, $jwt);
        $convId = $data['conv_id'];

        // Export hold (IocExportPolicy). Financial IOCs need analyst confirmation
        // (WS6); non-financial IOCs seen in one conversation are held pending
        // corroboration — CORROBORATE them (not confirm), so the MISP to_ids flag
        // keeps its type-based default instead of the confirmed=true override.
        $conn = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        $conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             SELECT indicator_id, 'confirmed', NULL, 'e2e', NOW() FROM indicator WHERE value_norm = :vn
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
            ['vn' => 'FR7612345678901234567890185'],
        );
        $this->corroborateByValueNorm($conn, 'billing@scam.test');
        $this->corroborateByValueNorm($conn, '33712345678');

        // Export MISP
        $client->request(
            'GET',
            "/api/v1/conversations/{$convId}/export/misp",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(200);
        $response = json_decode($client->getResponse()->getContent(), true);

        // Assert MISP Event structure
        $this->assertArrayHasKey('Event', $response);
        $event = $response['Event'];

        $this->assertArrayHasKey('info', $event);
        $this->assertStringContainsString($convId, $event['info']);
        $this->assertSame(2, $event['threat_level_id']); // Medium
        $this->assertSame(1, $event['analysis']); // Ongoing
        $this->assertSame(3, $event['distribution']); // All communities

        // Assert Attributes
        $this->assertArrayHasKey('Attribute', $event);
        $this->assertCount(3, $event['Attribute']); // 3 IOCs

        // Find IOCs by type (order is not guaranteed)
        $emailIoc = null;
        $phoneIoc = null;
        $ibanIoc = null;

        foreach ($event['Attribute'] as $attr) {
            if ($attr['type'] === 'email-src') {
                $emailIoc = $attr;
            } elseif ($attr['type'] === 'phone-number') {
                $phoneIoc = $attr;
            } elseif ($attr['type'] === 'iban') {
                $ibanIoc = $attr;
            }
        }

        // Assert email IOC
        $this->assertNotNull($emailIoc, 'Email IOC should be present');
        $this->assertSame('Network activity', $emailIoc['category']);
        $this->assertSame('email-src', $emailIoc['type']);
        $this->assertSame('billing@scam.test', $emailIoc['value']);
        $this->assertTrue($emailIoc['to_ids']);
        $this->assertStringContainsString('B2B_invoice_change', $emailIoc['comment']);

        // Assert Tags
        $this->assertArrayHasKey('Tag', $emailIoc);
        $this->assertGreaterThanOrEqual(2, count($emailIoc['Tag'])); // At least TLP + scam type

        $tagNames = array_column($emailIoc['Tag'], 'name');
        $this->assertContains('tlp:amber', $tagNames);
        $this->assertContains('scam:type=B2B_invoice_change', $tagNames);

        // Assert phone IOC
        $this->assertNotNull($phoneIoc, 'Phone IOC should be present');
        $this->assertSame('Person', $phoneIoc['category']);
        $this->assertSame('phone-number', $phoneIoc['type']);
        $this->assertSame('33712345678', $phoneIoc['value']);
        $this->assertFalse($phoneIoc['to_ids']); // Phone numbers don't trigger IDS

        // Assert IBAN IOC
        $this->assertNotNull($ibanIoc, 'IBAN IOC should be present');
        $this->assertSame('Financial fraud', $ibanIoc['category']);
        $this->assertSame('iban', $ibanIoc['type']);
        $this->assertSame('FR7612345678901234567890185', $ibanIoc['value']);
        $this->assertTrue($ibanIoc['to_ids']);

        $ibanTagNames = array_column($ibanIoc['Tag'], 'name');
        $this->assertContains('tlp:red', $ibanTagNames); // IBAN has TLP RED
    }

    /**
     * Test that exporting MISP from a conversation without IOCs returns 404.
     *
     * Note: We reuse an existing conversation from fixtures instead of creating one
     * to avoid ConversationController validation issues.
     */
    public function testExportMispReturnsNotFoundForConversationWithoutIocs(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Get an existing conversation from fixtures
        $em = $client->getContainer()->get('doctrine')->getManager();
        $conversation = $em->getRepository(\App\Domain\Communication\Conversation::class)->findOneBy([]);

        $this->assertNotNull($conversation, 'Fixture conversation should exist');
        $convId = $conversation->getConvId();

        // Delete all IOCs for this conversation to ensure it has no IOCs
        $iocs = $em->getRepository(\App\Domain\Communication\ObservedIoc::class)
            ->createQueryBuilder('ioc')
            ->join('ioc.message', 'm')
            ->where('m.conversation = :conv')
            ->setParameter('conv', $conversation)
            ->getQuery()
            ->getResult();

        foreach ($iocs as $ioc) {
            $em->remove($ioc);
        }
        $em->flush();

        // Export MISP should return 404 when no IOCs found
        $client->request(
            'GET',
            "/api/v1/conversations/{$convId}/export/misp",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($client->getResponse()->getContent(), true);

        // Assert error message
        $this->assertArrayHasKey('error', $response);
        $this->assertSame('No IOCs found for conversation', $response['error']);
    }
}
