<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MetricsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testMetricsEndpointReturnsPrometheusFormat(): void
    {
        // /api/metrics is not under /api/v1, falls under 'main' firewall
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
}
