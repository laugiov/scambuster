<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

/**
 * Flow 1: Complete intelligence pipeline — Email -> IOC extraction -> STIX export.
 *
 * Verifies: ingest, regex IOC extraction (IBAN + URL), conversation IOC listing,
 * and STIX 2.1 bundle export with indicator objects.
 */
final class EmailToIocToStixFlowTest extends AbstractCriticalFlowTestCase
{
    public function test_complete_email_to_stix_pipeline(): void
    {
        $client = static::createClient();
        $jwt = $this->getJwt($client);

        // Step 1: Ingest an email containing an IBAN and a URL
        $body = "Please wire \xE2\x82\xAC5000 to IBAN DE89370400440532013000. More info at http://evil-scam.test/phishing";
        $ingestResult = $this->ingestEmail(
            $client,
            $jwt,
            'scammer-ioc@evil.test',
            'Wire transfer request',
            $body,
        );

        $msgId = $ingestResult['msg_id'];
        $convId = $ingestResult['conv_id'];
        $this->assertNotEmpty($msgId, 'msg_id must be non-empty');
        $this->assertNotEmpty($convId, 'conv_id must be non-empty');

        // Step 2: Extract IOCs with regex + persist
        $client->request(
            'POST',
            "/api/v1/communication/message/{$msgId}/extract-iocs",
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            ],
            json_encode(['method' => 'regex', 'persist' => true]),
        );

        $this->assertResponseStatusCodeSame(200, 'IOC extraction should succeed');
        $extractResult = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('iocs', $extractResult);
        $this->assertGreaterThanOrEqual(1, $extractResult['iocs_found'], 'At least 1 IOC expected');

        // Verify we found IBAN and/or URL types
        $foundTypes = array_column($extractResult['iocs'], 'type');
        $hasFinancialOrUrl = !empty(array_intersect(['iban', 'url'], $foundTypes));
        $this->assertTrue($hasFinancialOrUrl, 'Extracted IOCs should contain IBAN or URL. Got: ' . implode(', ', $foundTypes));

        // Step 3: Verify IOCs are linked to the conversation
        $client->request(
            'GET',
            "/api/v1/communication/conversation/{$convId}/iocs",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );

        $this->assertResponseIsSuccessful();
        $convIocs = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($convIocs, 'Conversation IOCs should be an array');
        $this->assertGreaterThanOrEqual(1, count($convIocs), 'At least 1 IOC linked to conversation');

        // Step 4: Export STIX bundle for the conversation
        $client->request(
            'GET',
            "/api/v1/conversations/{$convId}/export/stix",
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt],
        );

        $this->assertResponseIsSuccessful();
        $stixBundle = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('bundle', $stixBundle['type'], 'Response must be a STIX bundle');
        $this->assertArrayHasKey('objects', $stixBundle);
        $this->assertIsArray($stixBundle['objects']);
        $this->assertGreaterThan(0, count($stixBundle['objects']), 'STIX bundle must contain objects');

        // Step 5: Verify STIX bundle contains indicator objects
        $stixTypes = array_column($stixBundle['objects'], 'type');
        $this->assertContains('indicator', $stixTypes, 'STIX bundle must contain indicator objects');
    }
}
