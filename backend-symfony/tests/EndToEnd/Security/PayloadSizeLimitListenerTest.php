<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PayloadSizeLimitListenerTest extends WebTestCase
{
    public function testNormalPayloadIsAccepted(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'user@example.com', 'password' => 'test']));

        // May fail auth but should NOT be 413
        $this->assertNotSame(413, $client->getResponse()->getStatusCode());
    }

    public function testOversizedPayloadIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_LENGTH' => '2000000', // 2MB > 1MB limit
        ], '{}');

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
    }

    /**
     * Spec 063 — The /ingest/raw endpoint legitimately needs to accept
     * large multipart mails with attachments (real-world: 1.4 MB+ for a
     * single PDF, up to ~45 MB for a 25 MB binary attachment after
     * base64 expansion). The path-aware listener applies a 50 MB limit
     * instead of 1 MB on this prefix.
     */
    public function testIngestEndpointAcceptsLargePayload(): void
    {
        $client = static::createClient();

        // 5 MB payload — would be rejected on /auth/login but accepted on /ingest/raw
        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_LENGTH' => '5000000',
        ], '{}');

        // The PayloadSizeLimitListener does NOT reject (no 413). The actual
        // request still fails downstream (auth/validation), but NOT with 413.
        $this->assertNotSame(413, $client->getResponse()->getStatusCode());
    }

    /**
     * Spec 063 — Even on the ingest endpoint, payloads larger than the
     * 50 MB limit are rejected.
     */
    public function testIngestEndpointRejectsExtremelyLargePayload(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_LENGTH' => '60000000', // 60 MB > 50 MB ingest limit
        ], '{}');

        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
        $this->assertSame(52428800, $data['max_bytes']);
    }
}
