<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class LlmCostControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLlmCostEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLlmCostEndpointReturnsCostStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('current_month', $data);
        $this->assertArrayHasKey('per_purpose', $data);
        $this->assertArrayHasKey('daily_trend', $data);
        $this->assertArrayHasKey('limit_exceeded', $data);
    }

    public function testLlmCostEndpointCurrentMonthStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $currentMonth = $data['current_month'];
        $this->assertIsArray($currentMonth);
        $this->assertArrayHasKey('total_usd', $currentMonth);
        $this->assertArrayHasKey('limit_usd', $currentMonth);
        $this->assertArrayHasKey('pct_used', $currentMonth);
        $this->assertArrayHasKey('calls_count', $currentMonth);
    }

    public function testLlmCostEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
