<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for monitoring controllers.
 *
 * Verifies route registration, authentication requirements,
 * and basic response structure for all monitoring endpoints.
 */
final class MonitoringControllersSmokeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ------------------------------------------------------------------ //
    //  Pipeline health
    // ------------------------------------------------------------------ //

    public function testPipelineHealthRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-health');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPipelineHealthReturnsJsonForAuthedUser(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/pipeline-health', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Autonomy monitoring
    // ------------------------------------------------------------------ //

    public function testAutonomyMonitoringRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAutonomyMonitoringReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Injection monitoring
    // ------------------------------------------------------------------ //

    public function testInjectionMonitoringRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/injection');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInjectionMonitoringReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/injection', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Rate limits
    // ------------------------------------------------------------------ //

    public function testRateLimitsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRateLimitsReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/rate-limits', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Convergence history
    // ------------------------------------------------------------------ //

    public function testConvergenceHistoryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConvergenceHistoryReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/convergence-history', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  LLM cost
    // ------------------------------------------------------------------ //

    public function testLlmCostRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testLlmCostReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/llm-cost', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Audit log
    // ------------------------------------------------------------------ //

    public function testAuditLogRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/audit');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAuditLogReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/audit', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Conversation lifecycle
    // ------------------------------------------------------------------ //

    public function testConversationLifecycleRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConversationLifecycleReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/conversation-lifecycle', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Analytics endpoints
    // ------------------------------------------------------------------ //

    public function testIocDistributionRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/ioc-distribution');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIocDistributionReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/ioc-distribution', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testScamDistributionRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/scam-distribution');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testScamDistributionReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/scam-distribution', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testConversationTimelineRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/conversation-timeline');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testConversationTimelineReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/conversation-timeline', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testIocTimelineRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/ioc-timeline');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIocTimelineReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/ioc-timeline', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testCostTimelineRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/cost-timeline');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCostTimelineReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/cost-timeline', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testPipelineTimelineRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/pipeline-timeline');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPipelineTimelineReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/pipeline-timeline', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testWeeklyTrendsRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/weekly-trends');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWeeklyTrendsReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/weekly-trends', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testActivityFeedRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/activity-feed');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testActivityFeedReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/analytics/activity-feed', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ------------------------------------------------------------------ //
    //  Impact endpoints
    // ------------------------------------------------------------------ //

    public function testImpactSummaryRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testImpactSummaryReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/impact/summary', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testIocUniquenessRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/impact/ioc-uniqueness');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testIocUniquenessReturnsJson(): void
    {
        $this->client->request('GET', '/api/v1/impact/ioc-uniqueness', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
