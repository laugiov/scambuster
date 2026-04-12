<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AnalyticsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // --- IOC Timeline ---

    public function testIocTimelineRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/ioc-timeline');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIocTimelineReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/ioc-timeline');
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        $this->assertSame(30, $data['period_days']);
    }

    public function testIocTimelineCapsDaysAt90(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/ioc-timeline?days=200');
        $this->assertSame(90, $data['period_days']);
    }

    public function testIocTimelineDataPointsHaveCorrectKeys(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/ioc-timeline');

        if (\count($data['data']) > 0) {
            $point = $data['data'][0];
            $this->assertArrayHasKey('date', $point);
            $this->assertArrayHasKey('count', $point);
        }
    }

    // --- Conversation Timeline ---

    public function testConversationTimelineReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/conversation-timeline');
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('data', $data);

        if (\count($data['data']) > 0) {
            $point = $data['data'][0];
            $this->assertArrayHasKey('date', $point);
            $this->assertArrayHasKey('opened', $point);
            $this->assertArrayHasKey('closed', $point);
        }
    }

    public function testConversationTimelineWithCustomDays(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/conversation-timeline?days=7');
        $this->assertSame(7, $data['period_days']);
    }

    // --- IOC Distribution ---

    public function testIocDistributionReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/ioc-distribution');
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);

        if (\count($data['data']) > 0) {
            $entry = $data['data'][0];
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('count', $entry);
        }
    }

    // --- Scam Distribution ---

    public function testScamDistributionReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/scam-distribution');
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);

        if (\count($data['data']) > 0) {
            $entry = $data['data'][0];
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('count', $entry);
        }
    }

    // --- Cost Timeline ---

    public function testCostTimelineReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/cost-timeline');
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('data', $data);

        if (\count($data['data']) > 0) {
            $point = $data['data'][0];
            $this->assertArrayHasKey('date', $point);
            $this->assertArrayHasKey('cost_usd', $point);
        }
    }

    // --- Pipeline Timeline ---

    public function testPipelineTimelineReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/pipeline-timeline');
        $this->assertArrayHasKey('period_days', $data);
        $this->assertArrayHasKey('data', $data);

        if (\count($data['data']) > 0) {
            $point = $data['data'][0];
            $this->assertArrayHasKey('date', $point);
            $this->assertArrayHasKey('approved', $point);
            $this->assertArrayHasKey('fallback', $point);
            $this->assertArrayHasKey('rejected', $point);
        }
    }

    // --- Activity Feed ---

    public function testActivityFeedReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/activity-feed');
        $this->assertArrayHasKey('events', $data);
        $this->assertIsArray($data['events']);
        $this->assertLessThanOrEqual(10, \count($data['events']));

        if (\count($data['events']) > 0) {
            $event = $data['events'][0];
            $this->assertArrayHasKey('event_type', $event);
            $this->assertArrayHasKey('ref_id', $event);
            $this->assertArrayHasKey('ts', $event);
            $this->assertContains($event['event_type'], ['conversation_opened', 'reply_sent', 'ioc_extracted']);
        }
    }

    public function testActivityFeedCapsLimitAt50(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/activity-feed?limit=100');
        $this->assertLessThanOrEqual(50, \count($data['events']));
    }

    // --- Weekly Trends ---

    public function testWeeklyTrendsReturnsValidStructure(): void
    {
        $data = $this->authenticatedGet('/api/v1/monitoring/analytics/weekly-trends');
        $this->assertArrayHasKey('trends', $data);
        $this->assertIsArray($data['trends']);
        $this->assertCount(4, $data['trends']);

        $metrics = array_column($data['trends'], 'metric');
        $this->assertContains('conversations', $metrics);
        $this->assertContains('iocs', $metrics);
        $this->assertContains('replies', $metrics);
        $this->assertContains('cost', $metrics);

        foreach ($data['trends'] as $trend) {
            $this->assertArrayHasKey('metric', $trend);
            $this->assertArrayHasKey('current', $trend);
            $this->assertArrayHasKey('previous', $trend);
            $this->assertArrayHasKey('delta_pct', $trend);
        }
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
