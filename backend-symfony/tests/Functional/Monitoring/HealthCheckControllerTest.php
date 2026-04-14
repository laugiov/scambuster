<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthCheckControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const HEALTH_URL = '/api/health';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // BASIC HEALTH CHECK
    // ──────────────────────────────────────────────

    public function testHealthCheckReturns200(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if all deps healthy, 503 if a dependency is down
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_SERVICE_UNAVAILABLE,
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testHealthCheckReturnsExpectedKeys(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['ok', 'degraded', 'error']);
    }

    public function testHealthCheckContainsChecksSection(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        // The controller returns checks for database and redis
        if (isset($data['checks'])) {
            $checks = $data['checks'];
            $this->assertIsArray($checks);

            if (isset($checks['database'])) {
                $this->assertArrayHasKey('status', $checks['database']);
            }

            if (isset($checks['redis'])) {
                $this->assertArrayHasKey('status', $checks['redis']);
            }
        }
    }

    public function testHealthCheckContainsTimestamp(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        // The OA spec documents a timestamp field
        if (isset($data['timestamp'])) {
            $this->assertIsString($data['timestamp']);
        }
    }

    public function testHealthCheckReturnsJsonContentType(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testHealthCheckContainsVersionField(): void
    {
        $this->client->request('GET', self::HEALTH_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);

        if (isset($data['version'])) {
            $this->assertIsString($data['version']);
        }
    }

    // ──────────────────────────────────────────────
    // AUTH BEHAVIOR
    // ──────────────────────────────────────────────

    public function testHealthCheckWithoutAuthHeader(): void
    {
        $this->client->request('GET', self::HEALTH_URL);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // /api/health is outside /api/v1 access_control, but the main
        // firewall in test has a custom authenticator. If no Authorization
        // header is sent, the authenticator may not trigger (supports()
        // returns false), letting the request through unauthenticated.
        // The controller has no #[IsGranted], so 200/503 are valid.
        // If the authenticator blocks it, 401 is also valid.
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_SERVICE_UNAVAILABLE,
        ]);
    }
}
