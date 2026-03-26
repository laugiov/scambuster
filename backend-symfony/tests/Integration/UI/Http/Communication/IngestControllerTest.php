<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class IngestControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testIngestRawRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIngestRawRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json{{{');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testIngestRawRejectsEmptyPayload(): void
    {
        $this->client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        // Empty payload should fail validation (422) since required fields are missing
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testIngestRawReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testIngestRawRejectsPayloadMissingRequiredFields(): void
    {
        $payload = [
            'subject' => 'Test email',
            // Missing required fields like raw_rfc822 / from / body etc.
        ];

        $this->client->request('POST', '/api/v1/communication/ingest/raw', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
