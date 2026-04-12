<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetAllScambaitingStatsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testGetAllStatsReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testGetAllStatsDataItemsHaveScamTypeCode(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['data'] as $item) {
            $this->assertArrayHasKey('scam_type_code', $item);
            $this->assertIsString($item['scam_type_code']);
            $this->assertNotEmpty($item['scam_type_code']);
        }
    }

    public function testGetAllStatsDataItemsHaveTotalSessions(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['data'] as $item) {
            $this->assertArrayHasKey('total_sessions', $item);
            $this->assertIsInt($item['total_sessions']);
            $this->assertGreaterThanOrEqual(0, $item['total_sessions']);
        }
    }

    public function testGetAllStatsDataItemsHaveAvgReward(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['data'] as $item) {
            $this->assertArrayHasKey('avg_reward', $item);
            $this->assertIsNumeric($item['avg_reward']);
        }
    }

    public function testGetAllStatsSuccessIsTrue(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(true, $data['success']);
    }

    public function testGetAllStatsWithAdminAuth(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
    }

    public function testGetAllStatsDataIsArrayNotObject(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        // data should be a sequential array, not an associative object
        $this->assertIsArray($data['data']);

        if (count($data['data']) > 0) {
            // First key should be 0 (sequential array)
            $this->assertArrayHasKey(0, $data['data']);
        }
    }

    public function testGetAllStatsDataItemsHaveAllExpectedFields(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        foreach ($data['data'] as $item) {
            $this->assertArrayHasKey('scam_type_code', $item);
            $this->assertArrayHasKey('total_sessions', $item);
            $this->assertArrayHasKey('avg_reward', $item);
            $this->assertIsString($item['scam_type_code']);
            $this->assertIsInt($item['total_sessions']);
            $this->assertIsNumeric($item['avg_reward']);
        }
    }

    public function testGetAllStatsReturnsHttp200(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
