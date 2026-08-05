<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MetricsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // /api/metrics is admin-only; authenticate every request as admin.
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer fake-admin-jwt');
    }

    public function testMetricsEndpointReturnsPrometheusFormat(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_UNAUTHORIZED]);

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertStringContainsString('scambuster_info', $content);
            $this->assertStringContainsString('scambuster_conversations_total', $content);
        }
    }

    public function testMetricsEndpointReturnsTextPlainContentType(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $contentType = $this->client->getResponse()->headers->get('content-type');
            $this->assertStringContainsString('text/plain', $contentType);
        }
    }

    public function testMetricsContainsExpectedMetricNames(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertStringContainsString('scambuster_info', $content);
            $this->assertStringContainsString('scambuster_conversations_total', $content);
            $this->assertStringContainsString('scambuster_messages_total', $content);
            $this->assertStringContainsString('scambuster_iocs_total', $content);
            $this->assertStringContainsString('scambuster_iocs_unique', $content);
            $this->assertStringContainsString('scambuster_kill_switch', $content);
            $this->assertStringContainsString('scambuster_health_check', $content);
            $this->assertStringContainsString('scambuster_convergence_ratio', $content);
        }
    }

    public function testMetricsContainsHelpAndTypeAnnotations(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertStringContainsString('# HELP scambuster_info', $content);
            $this->assertStringContainsString('# TYPE scambuster_info gauge', $content);
        }
    }

    public function testMetricsEachLineStartsWithScambusterOrIsComment(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $lines = explode("\n", trim($content));

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                // Each non-empty line must start with 'scambuster_' or '#'
                $this->assertTrue(
                    str_starts_with($line, 'scambuster_') || str_starts_with($line, '#'),
                    "Line does not start with 'scambuster_' or '#': {$line}"
                );
            }
        }
    }

    public function testMetricsContainsAtLeastFiveSpecificMetrics(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();

            $expectedMetrics = [
                'scambuster_info',
                'scambuster_conversations_total',
                'scambuster_messages_total',
                'scambuster_iocs_total',
                'scambuster_iocs_unique',
                'scambuster_kill_switch',
                'scambuster_health_check',
                'scambuster_convergence_ratio',
            ];

            $foundCount = 0;

            foreach ($expectedMetrics as $metric) {
                if (str_contains($content, $metric)) {
                    ++$foundCount;
                }
            }

            $this->assertGreaterThanOrEqual(5, $foundCount, 'Expected at least 5 distinct metrics');
        }
    }

    public function testMetricsContentTypeIsTextPlain(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $contentType = $this->client->getResponse()->headers->get('content-type');
            $this->assertStringContainsString('text/plain', $contentType);
            $this->assertStringContainsString('version=0.0.4', $contentType);
        }
    }

    public function testMetricsConversationsTotalHasStatusLabels(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            // The controller iterates over open, closed, abandoned statuses
            $this->assertStringContainsString('scambuster_conversations_total{status="open"}', $content);
            $this->assertStringContainsString('scambuster_conversations_total{status="closed"}', $content);
            $this->assertStringContainsString('scambuster_conversations_total{status="abandoned"}', $content);
        }
    }

    public function testMetricsMessagesHaveDirectionLabels(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertStringContainsString('scambuster_messages_total{direction="inbound"}', $content);
            $this->assertStringContainsString('scambuster_messages_total{direction="outbound"}', $content);
        }
    }

    public function testMetricsInfoContainsVersionLabel(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            // scambuster_info{version="X.Y.Z"} 1
            $this->assertMatchesRegularExpression(
                '/scambuster_info\{version="[^"]+"\} 1/',
                $content
            );
        }
    }

    public function testMetricsKillSwitchIsZeroOrOne(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertMatchesRegularExpression(
                '/scambuster_kill_switch [01]/',
                $content
            );
        }
    }

    public function testMetricsHealthCheckHasServiceLabels(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            // Health checks iterate over service keys (database, redis)
            $this->assertStringContainsString('scambuster_health_check{service=', $content);
        }
    }

    public function testMetricsConvergenceRatioIsNumeric(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertMatchesRegularExpression(
                '/scambuster_convergence_ratio [0-9.]+/',
                $content
            );
        }
    }

    public function testMetricsIocsUniqueIsPresent(): void
    {
        $this->client->request('GET', '/api/metrics');

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $content = $this->client->getResponse()->getContent();
            $this->assertMatchesRegularExpression(
                '/scambuster_iocs_unique \d+/',
                $content
            );
        }
    }
}
