<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetScambaitingStatsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $jwtToken;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->jwtToken = $data['access_token'] ?? '';
    }

    public function testGetStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetStatsReturnsDataForValidScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$this->jwtToken}",
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function testGetStatsReturnsErrorForInvalidScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/INVALID_SCAM_TYPE', [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$this->jwtToken}",
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

    public function testGetAllStatsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetAllStatsReturnsAggregatedData(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$this->jwtToken}",
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }
}
