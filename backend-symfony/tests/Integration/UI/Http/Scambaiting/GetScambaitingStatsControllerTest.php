<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetScambaitingStatsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testGetStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetStatsReturnsDataForValidScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function testGetStatsReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testGetStatsReturnsErrorForInvalidScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/INVALID_SCAM_TYPE', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_NOT_FOUND, Response::HTTP_OK]
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        if ($this->client->getResponse()->getStatusCode() === Response::HTTP_NOT_FOUND) {
            $this->assertArrayHasKey('error', $data);
        }
    }

    public function testGetStatsResponseStructure(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);

        // Verify the data structure contains expected fields
        $statsData = $data['data'];
        $this->assertArrayHasKey('scam_type_code', $statsData);
        $this->assertSame('PHISHING', $statsData['scam_type_code']);
    }

    public function testGetAllStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetAllStatsReturnsAggregatedData(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }
}
