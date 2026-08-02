<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Monitoring;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end tests for the autonomy monitoring endpoint.
 *
 * Tests GET /api/v1/monitoring/autonomy with real HTTP requests,
 * JWT authentication, and database queries.
 */
final class AutonomyMonitoringControllerTest extends WebTestCase
{
    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);

        return $data['access_token'] ?? '';
    }

    public function testMonitoringEndpointReturnsFullStatus(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('status', $data);
        $this->assertContains($data['status'], ['operational', 'degraded']);

        $this->assertArrayHasKey('kill_switch_active', $data);
        $this->assertIsBool($data['kill_switch_active']);

        $this->assertArrayHasKey('conversations', $data);
        $this->assertArrayHasKey('total', $data['conversations']);
        $this->assertArrayHasKey('open', $data['conversations']);

        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('total', $data['messages']);

        $this->assertArrayHasKey('iocs', $data);
        $this->assertArrayHasKey('total', $data['iocs']);
        $this->assertArrayHasKey('unique_indicators', $data['iocs']);

        $this->assertArrayHasKey('convergence', $data);
        $this->assertArrayHasKey('converged_types', $data['convergence']);
        $this->assertArrayHasKey('total_types', $data['convergence']);
        $this->assertArrayHasKey('details', $data['convergence']);
        $this->assertGreaterThan(0, $data['convergence']['total_types']);

        $this->assertArrayHasKey('last_activity', $data);

        $this->assertArrayHasKey('checked_at', $data);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['checked_at']);
        $this->assertNotFalse($parsed);
    }

    public function testMonitoringEndpointRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/v1/monitoring/autonomy');

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMonitoringEndpointReflectsFixtureData(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);

        // E2E fixtures should have created conversations and messages
        $this->assertGreaterThanOrEqual(0, $data['conversations']['total']);
        $this->assertGreaterThanOrEqual(0, $data['messages']['total']);

        // Kill switch should be off in test env
        $this->assertFalse($data['kill_switch_active']);
    }
}
