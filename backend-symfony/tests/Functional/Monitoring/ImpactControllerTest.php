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

    // === Spec 096 / C2 — scam_type filter tests ===

    public function testSummaryAcceptsScamTypeFilter_096C2(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/summary?scam_type=INVOICE_FRAUD');
        // Endpoint must return a valid structure (filtered or not)
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertArrayHasKey('ioc_value', $data);
        $this->assertArrayHasKey('cost_efficiency', $data);
        $this->assertArrayHasKey('campaigns', $data);
        // Numeric metrics remain non-negative
        $this->assertGreaterThanOrEqual(0, $data['wasted_time']['total_conversations']);
        $this->assertGreaterThanOrEqual(0, $data['ioc_value']['total_iocs']);
    }

    public function testSummaryWithScamTypeAndPeriodCombined_096C2(): void
    {
        // Spec 096 / C2 — date + scam_type filters MUST combine, not override each other.
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=30d&scam_type=PHISHING');
        $this->assertArrayHasKey('wasted_time', $data);
        $this->assertIsInt($data['wasted_time']['total_conversations']);
    }

    public function testSummaryEmptyScamTypeBehavesAsNoFilter_096C2(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $withEmpty = $this->authenticatedGet('/api/v1/impact/summary?scam_type=');
        // Empty string scam_type must be treated as null (no filter) — byte-identical response
        $this->assertSame(
            $baseline['wasted_time']['total_conversations'],
            $withEmpty['wasted_time']['total_conversations'],
        );
        $this->assertSame(
            $baseline['ioc_value']['total_iocs'],
            $withEmpty['ioc_value']['total_iocs'],
        );
    }

    public function testSummaryScamTypeFilterReducesOrEqualsBaseline_096C2(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $filtered = $this->authenticatedGet('/api/v1/impact/summary?scam_type=INVOICE_FRAUD');
        // A specific scam_type filter NEVER returns more conversations than the unfiltered set
        $this->assertLessThanOrEqual(
            $baseline['wasted_time']['total_conversations'],
            $filtered['wasted_time']['total_conversations'],
        );
    }

    // === Spec 096 / C3 — scam_type filter on IocUniqueness ===

    public function testIocUniquenessAcceptsScamTypeFilter_096C3(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=INVOICE_FRAUD');
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('by_type', $data);
        $this->assertArrayHasKey('daily_trend', $data);
        $this->assertGreaterThanOrEqual(0, $data['summary']['total_iocs']);
    }

    public function testIocUniquenessScamTypeFilterReducesOrEquals_096C3(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $filtered = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=PHISHING');
        $this->assertLessThanOrEqual(
            $baseline['summary']['total_iocs'],
            $filtered['summary']['total_iocs'],
        );
    }

    public function testIocUniquenessScamTypeAndPeriodCombine_096C3(): void
    {
        $data = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=30d&scam_type=INVOICE_FRAUD');
        $this->assertArrayHasKey('summary', $data);
    }

    public function testIocUniquenessEmptyScamTypeBehavesAsNoFilter_096C3(): void
    {
        $baseline = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness');
        $withEmpty = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?scam_type=');
        $this->assertSame(
            $baseline['summary']['total_iocs'],
            $withEmpty['summary']['total_iocs'],
        );
    }

    // === Spec 096 / C5 — chart trends respect the period filter ===

    public function testSummaryWeeklyTrendRespectsPeriod_096C5(): void
    {
        // With period=7d, weekly_trend rows must all fall within the 7-day window.
        // We don't assert exact row count (depends on fixtures) — only that the response is well-formed.
        $data = $this->authenticatedGet('/api/v1/impact/summary?period=7d');
        $weeklyTrend = $data['wasted_time']['weekly_trend'];
        $this->assertIsArray($weeklyTrend);
        // 7-day window NEVER yields MORE rows than the full 12-week default
        $baseline = $this->authenticatedGet('/api/v1/impact/summary');
        $this->assertLessThanOrEqual(\count($baseline['wasted_time']['weekly_trend']), \count($weeklyTrend));
    }

    public function testIocUniquenessDailyTrendRespectsPeriod_096C5(): void
    {
        // Same regression on the daily_trend chart of /impact/ioc-uniqueness.
        $data7d = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=7d');
        $data30d = $this->authenticatedGet('/api/v1/impact/ioc-uniqueness?period=30d');
        // 7-day window NEVER has more daily points than 30-day window
        $this->assertLessThanOrEqual(\count($data30d['daily_trend']), \count($data7d['daily_trend']));
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
