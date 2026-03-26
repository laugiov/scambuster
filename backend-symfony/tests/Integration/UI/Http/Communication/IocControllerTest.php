<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class IocControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ---- GET /api/v1/iocs ----

    public function testListIocsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/iocs');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListIocsReturnsJsonArray(): void
    {
        $this->client->request('GET', '/api/v1/iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testListIocsWithMinScoreFilter(): void
    {
        $this->client->request('GET', '/api/v1/iocs?min_score=0.5', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    // ---- POST /api/v1/iocs/enriched ----

    public function testIngestEnrichedIocRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIngestEnrichedIocRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testIngestEnrichedIocRejectsMissingIocField(): void
    {
        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['message_id' => 'test']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('ioc', $data['error']);
    }

    public function testIngestEnrichedIocRejectsMissingRequiredIocFields(): void
    {
        $payload = [
            'ioc' => [
                'type' => 'url',
                // missing value, value_norm, source, first_seen
            ],
        ];

        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing required IOC field', $data['error']);
    }

    public function testIngestEnrichedIocRejectsInvalidIocType(): void
    {
        $payload = [
            'ioc' => [
                'type' => 'invalid_type_xyz',
                'value' => 'https://evil.com',
                'value_norm' => 'evil.com',
                'source' => 'body',
                'first_seen' => '2026-01-01T00:00:00Z',
            ],
        ];

        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid IOC type', $data['error']);
    }

    public function testIngestEnrichedIocReturns404ForUnknownMessage(): void
    {
        $payload = [
            'message_id' => '<nonexistent@example.com>',
            'ioc' => [
                'type' => 'url',
                'value' => 'https://evil.com',
                'value_norm' => 'evil.com',
                'source' => 'body',
                'first_seen' => '2026-01-01T00:00:00Z',
            ],
        ];

        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        // Should be 404 because message not found
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testIngestEnrichedIocReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/iocs/enriched', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['ioc' => 'not-an-array']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ---- PATCH /api/v1/iocs/{obs_id}/enrich ----

    public function testUpdateIocEnrichmentRequiresAuthentication(): void
    {
        $this->client->request('PATCH', '/api/v1/iocs/00000000-0000-0000-0000-000000000001/enrich');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testUpdateIocEnrichmentRejectsInvalidJson(): void
    {
        $this->client->request('PATCH', '/api/v1/iocs/00000000-0000-0000-0000-000000000001/enrich', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testUpdateIocEnrichmentRejectsMissingEnrichmentField(): void
    {
        $this->client->request('PATCH', '/api/v1/iocs/00000000-0000-0000-0000-000000000001/enrich', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['foo' => 'bar']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('enrichment', $data['error']);
    }

    public function testUpdateIocEnrichmentReturns404ForUnknownObsId(): void
    {
        $payload = [
            'enrichment' => [
                'virustotal' => [
                    'harmless' => 50,
                    'malicious' => 5,
                    'suspicious' => 2,
                    'undetected' => 10,
                ],
            ],
        ];

        $this->client->request('PATCH', '/api/v1/iocs/00000000-0000-0000-0000-000000000099/enrich', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateIocEnrichmentReturnsJsonContentType(): void
    {
        $this->client->request('PATCH', '/api/v1/iocs/00000000-0000-0000-0000-000000000001/enrich', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['not' => 'enrichment']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
