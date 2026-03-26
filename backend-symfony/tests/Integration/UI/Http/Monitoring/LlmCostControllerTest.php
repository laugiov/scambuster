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

    public function testLlmCostPerPurposeIsObject(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        // per_purpose is an object (decoded as associative array or empty array)
        $this->assertIsArray($data['per_purpose']);
    }

    public function testLlmCostDailyTrendIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['daily_trend']);

        // If entries exist, each should have date, cost_usd, calls
        foreach ($data['daily_trend'] as $entry) {
            $this->assertArrayHasKey('date', $entry);
            $this->assertArrayHasKey('cost_usd', $entry);
            $this->assertArrayHasKey('calls', $entry);
        }
    }

    public function testLlmCostLimitExceededIsBoolean(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsBool($data['limit_exceeded']);
    }

    public function testLlmCostCurrentMonthHasTotalUsdAndCallsCount(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $currentMonth = $data['current_month'];

        $this->assertArrayHasKey('total_usd', $currentMonth);
        $this->assertArrayHasKey('calls_count', $currentMonth);
        $this->assertIsNumeric($currentMonth['total_usd']);
        $this->assertIsInt($currentMonth['calls_count']);
    }

    public function testLlmCostCurrentMonthHasTokenFields(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $currentMonth = $data['current_month'];

        $this->assertArrayHasKey('total_prompt_tokens', $currentMonth);
        $this->assertArrayHasKey('total_completion_tokens', $currentMonth);
    }
}
