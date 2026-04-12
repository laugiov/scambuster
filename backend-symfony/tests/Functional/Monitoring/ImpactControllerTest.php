<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ImpactControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // --- Summary: Auth ---

    public function testSummaryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- Summary: Structure ---

    public function testSummaryReturnsExpectedStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
        $this->assertArrayHasKey('cost_efficiency', $data);
        $this->assertArrayHasKey('campaigns', $data);
    }

    public function testSummaryWastedTimeHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $wt = $data['wasted_time'];
        $this->assertArrayHasKey('total_hours', $wt);
        $this->assertArrayHasKey('total_conversations', $wt);
        $this->assertArrayHasKey('avg_hours', $wt);
        $this->assertArrayHasKey('weekly_trend', $wt);
    }

    public function testSummaryIocValueHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $ioc = $data['ioc_value'];
        $this->assertArrayHasKey('total_iocs', $ioc);
        $this->assertArrayHasKey('novel_iocs', $ioc);
        $this->assertArrayHasKey('novel_pct', $ioc);
        $this->assertArrayHasKey('financial_iocs', $ioc);
        $this->assertArrayHasKey('by_type', $ioc);
    }

    public function testSummaryCostHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $cost = $data['cost_efficiency'];
        $this->assertArrayHasKey('total_cost_usd', $cost);
        $this->assertArrayHasKey('cost_per_ioc_usd', $cost);
    }

    public function testSummaryCampaignsHasCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $campaigns = $data['campaigns'];
        $this->assertArrayHasKey('total', $campaigns);
        $this->assertArrayHasKey('promoted', $campaigns);
        $this->assertArrayHasKey('top_campaigns', $campaigns);
    }

    // --- Summary: Periods ---

    public function testSummaryWithPeriod7d(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=7d');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
    }

    public function testSummaryWithPeriodAll(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=all');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
    }

    // --- IOC Uniqueness: Auth ---

    public function testIocUniquenessRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/ioc-uniqueness');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // --- IOC Uniqueness: Structure ---

    public function testIocUniquenessReturnsStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
    }

    public function testIocUniquenessWithTypeFilter(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?ioc_type=url');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
    }

    // --- Summary: Content-Type ---

    public function testSummaryReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $contentType = $this->client->getResponse()->headers->get('Content-Type');
        $this->assertNotNull($contentType);
        $this->assertStringContainsString('json', $contentType);
    }

    // --- Summary: Numeric checks ---

    public function testSummaryNovelPctIsNumeric(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $novelPct = $data['ioc_value']['novel_pct'];
        $this->assertIsNumeric($novelPct);
        $this->assertNotNull($novelPct);
    }

    public function testSummaryCostPerIocIsNumeric(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary');
        $costPerIoc = $data['cost_efficiency']['cost_per_ioc_usd'];
        $this->assertIsNumeric($costPerIoc);
        $this->assertNotNull($costPerIoc);
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticatedGet(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);

        $data = json_decode($content, true);
        $this->assertIsArray($data);

        return $data;
    }
}
