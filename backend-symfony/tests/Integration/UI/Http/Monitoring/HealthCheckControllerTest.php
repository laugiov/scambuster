<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthCheckControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testHealthCheckReturnsStatusWithoutAuth(): void
    {
        // /api/health is not under /api/v1, so it falls under the 'main' firewall
        // which has no access_control requiring auth
        $this->client->request('GET', '/api/health');

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should be 200 (ok) or 503 (dependency down) - both are valid
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_SERVICE_UNAVAILABLE]);
    }

    public function testHealthCheckReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('checks', $data);
    }

    public function testHealthCheckChecksContainDatabaseAndRedis(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $checks = $data['checks'] ?? [];
        $this->assertArrayHasKey('database', $checks);
        $this->assertArrayHasKey('redis', $checks);
    }

    public function testHealthCheckDatabaseCheckHasStatusAndLatency(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $dbCheck = $data['checks']['database'] ?? [];
        $this->assertArrayHasKey('status', $dbCheck);
        $this->assertArrayHasKey('latency_ms', $dbCheck);
    }

    public function testHealthCheckReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/health');

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testHealthCheckVersionIsString(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('version', $data);
        $this->assertIsString($data['version']);
        $this->assertNotEmpty($data['version']);
    }

    public function testHealthCheckTimestampIsIso8601Format(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertIsString($data['timestamp']);

        // Verify it can be parsed as a valid datetime (ISO 8601)
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $data['timestamp']);

        if ($parsed === false) {
            // Try ISO 8601 without timezone offset (e.g. 2026-03-26T12:00:00Z or similar)
            $parsed = new \DateTimeImmutable($data['timestamp']);
        }

        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
    }

    public function testHealthCheckStatusIsValidValue(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['ok', 'degraded', 'error']);
    }

    public function testHealthCheckWhenDatabaseIsAvailable(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statusCode = $this->client->getResponse()->getStatusCode();

        // If database is available, status should be 200 and database check should be ok
        if ($statusCode === Response::HTTP_OK) {
            $this->assertSame('ok', $data['status']);
            $this->assertSame('ok', $data['checks']['database']['status']);
            $this->assertIsInt($data['checks']['database']['latency_ms']);
            $this->assertGreaterThanOrEqual(0, $data['checks']['database']['latency_ms']);
        }
    }

    public function testHealthCheckVersionMatchesSemverFormat(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('version', $data);
        // Version should match semver-like format (e.g., 1.3.0)
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $data['version'],
            'Version should follow semver format (X.Y.Z)'
        );
    }

    public function testHealthCheckReturns200WhenHealthy(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statusCode = $this->client->getResponse()->getStatusCode();

        // The controller has a branch: status ok => 200, otherwise => 503
        if ($data['status'] === 'ok') {
            $this->assertSame(Response::HTTP_OK, $statusCode);
        } else {
            $this->assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $statusCode);
        }
    }

    public function testHealthCheckUptimeSecondsIsPresent(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('uptime_seconds', $data);
        $this->assertIsInt($data['uptime_seconds']);
        $this->assertGreaterThanOrEqual(0, $data['uptime_seconds']);
    }

    public function testHealthCheckRedisCheckHasStatusAndLatency(): void
    {
        $this->client->request('GET', '/api/health');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $redisCheck = $data['checks']['redis'] ?? [];
        $this->assertArrayHasKey('status', $redisCheck);
        $this->assertArrayHasKey('latency_ms', $redisCheck);
        $this->assertContains($redisCheck['status'], ['ok', 'error']);
    }
}
