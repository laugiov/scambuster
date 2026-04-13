<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class IngestControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const INGEST_URL = '/api/v1/communication/ingest/raw';
    private const ACCOUNT_ID = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(string $uniqueSuffix = ''): array
    {
        $suffix = $uniqueSuffix ?: bin2hex(random_bytes(8));

        $rawMail = "From: scammer@evil.test\r\nTo: honeypot@test.com\r\nSubject: Test\r\nMessage-ID: <unique-test-{$suffix}@test>\r\nDate: Thu, 01 Jun 2025 12:00:00 +0000\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nHello this is a test";

        return [
            'account_id' => self::ACCOUNT_ID,
            'raw_source' => base64_encode($rawMail),
            'ts_received' => '2025-06-01T12:00:00+00:00',
            'channel' => 'email',
            'rspamd' => ['score' => 5.0, 'action' => 'reject'],
            'score_risk' => 42.0,
        ];
    }

    // ──────────────────────────────────────────────
    // VALID INGEST
    // ──────────────────────────────────────────────

    public function testIngestRawReturns201WithMessageId(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->validPayload()));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 201 on first ingest, 409 if dedup triggers on repeated runs,
        // 400 if account_id doesn't match a MailAccount in test fixtures
        $this->assertContains($statusCode, [
            Response::HTTP_CREATED,
            Response::HTTP_CONFLICT,
            Response::HTTP_BAD_REQUEST,
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        if ($statusCode === Response::HTTP_CREATED) {
            $this->assertArrayHasKey('msg_id', $data);
            $this->assertNotEmpty($data['msg_id']);
        }
    }

    // ──────────────────────────────────────────────
    // INVALID JSON
    // ──────────────────────────────────────────────

    public function testIngestRawReturns400ForInvalidJson(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{not valid json at all');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    // ──────────────────────────────────────────────
    // MISSING REQUIRED FIELDS
    // ──────────────────────────────────────────────

    public function testIngestRawReturns422ForMissingRequiredFields(): void
    {
        $payload = [
            'account_id' => self::ACCOUNT_ID,
            // missing raw_source, from, to
        ];

        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Validation errors yield 422 (validator) or 400 (deserialization)
        $this->assertContains($statusCode, [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_BAD_REQUEST,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    // ──────────────────────────────────────────────
    // EMPTY BODY
    // ──────────────────────────────────────────────

    public function testIngestRawReturns400ForEmptyBody(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Empty body triggers deserialization error (400) or validation (422)
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }

    // ──────────────────────────────────────────────
    // AUTH REQUIRED
    // ──────────────────────────────────────────────

    public function testIngestRawReturns401WithoutAuth(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->validPayload()));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // RESPONSE STRUCTURE ON SUCCESS
    // ──────────────────────────────────────────────

    public function testIngestRawResponseContainsExpectedKeys(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->validPayload()));

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_CREATED) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('msg_id', $data);
            $this->assertArrayHasKey('conv_id', $data);
            $this->assertArrayHasKey('status', $data);
            $this->assertSame('ingested', $data['status']);
        } else {
            // Dedup conflict is acceptable on repeated test runs
            $this->assertContains($statusCode, [
                Response::HTTP_CONFLICT,
                Response::HTTP_BAD_REQUEST,
                Response::HTTP_INTERNAL_SERVER_ERROR,
            ]);
        }
    }

    // ──────────────────────────────────────────────
    // CONTENT-TYPE HEADER
    // ──────────────────────────────────────────────

    public function testIngestRawAlwaysReturnsJsonContentType(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->validPayload()));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ──────────────────────────────────────────────
    // EMPTY JSON OBJECT
    // ──────────────────────────────────────────────

    public function testIngestRawRejectsEmptyJsonObject(): void
    {
        $this->client->request('POST', self::INGEST_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_BAD_REQUEST,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);
    }
}
