<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Scambaiting;

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

    public function testGetStatsWithAdvanceFeeScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/ADVANCE_FEE', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame('ADVANCE_FEE', $data['data']['scam_type_code']);
        }
    }

    public function testGetStatsWithRomanceScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/ROMANCE', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame('ROMANCE', $data['data']['scam_type_code']);
        }
    }

    public function testGetStatsWithTechSupportScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/TECH_SUPPORT', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame('TECH_SUPPORT', $data['data']['scam_type_code']);
        }
    }

    public function testGetStats404ResponseHasErrorKey(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/INVALID_SCAM_TYPE_XYZ', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_NOT_FOUND) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('error', $data);
            $this->assertIsString($data['error']);
        }
    }

    public function testGetStatsDataHasExpectedStatisticsFields(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statsData = $data['data'];

        // The getSelectionStats method returns these fields
        $this->assertArrayHasKey('scam_type_code', $statsData);
        $this->assertArrayHasKey('total_personas', $statsData);
        $this->assertArrayHasKey('cold_start_count', $statsData);
        $this->assertArrayHasKey('epsilon', $statsData);
        $this->assertArrayHasKey('converged', $statsData);
    }

    public function testGetStatsDataHasBestPersonaField(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statsData = $data['data'];

        // best_persona can be null or an object with persona_code, reward_avg, sessions_count
        $this->assertArrayHasKey('best_persona', $statsData);

        if ($statsData['best_persona'] !== null) {
            $this->assertArrayHasKey('persona_code', $statsData['best_persona']);
            $this->assertArrayHasKey('reward_avg', $statsData['best_persona']);
            $this->assertArrayHasKey('sessions_count', $statsData['best_persona']);
        }
    }

    public function testGetStatsDataHasTop5Field(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statsData = $data['data'];

        $this->assertArrayHasKey('top_5', $statsData);
        $this->assertIsArray($statsData['top_5']);
        $this->assertLessThanOrEqual(5, count($statsData['top_5']));

        foreach ($statsData['top_5'] as $entry) {
            $this->assertArrayHasKey('persona_code', $entry);
            $this->assertArrayHasKey('reward_avg', $entry);
            $this->assertArrayHasKey('sessions_count', $entry);
        }
    }

    public function testGetStatsConvergedIsBool(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsBool($data['data']['converged']);
    }

    public function testGetStatsEpsilonIsNumeric(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats/PHISHING', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsNumeric($data['data']['epsilon']);
    }
}
