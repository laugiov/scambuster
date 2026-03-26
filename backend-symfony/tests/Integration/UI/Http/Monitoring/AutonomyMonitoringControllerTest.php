<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AutonomyMonitoringControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAutonomyEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAutonomyEndpointReturnsSuccessWithUserAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testAutonomyEndpointReturnsExpectedStructure(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('kill_switch_active', $data);
        $this->assertArrayHasKey('conversations', $data);
        $this->assertArrayHasKey('messages', $data);
        $this->assertArrayHasKey('iocs', $data);
        $this->assertArrayHasKey('convergence', $data);
        $this->assertArrayHasKey('last_activity', $data);
        $this->assertArrayHasKey('checked_at', $data);
    }

    public function testAutonomyEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testAutonomyEndpointStatusIsString(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsString($data['status']);
        $this->assertContains($data['status'], ['operational', 'degraded', 'error']);
    }

    public function testAutonomyEndpointKillSwitchIsBoolean(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsBool($data['kill_switch_active']);
    }

    public function testAutonomyEndpointConversationsIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['conversations']);
        $this->assertIsArray($data['messages']);
        $this->assertIsArray($data['iocs']);
    }

    public function testAutonomyEndpointWithAdminAuth(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('status', $data);
    }

    public function testAutonomyEndpointCheckedAtIsIso8601(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('checked_at', $data);
        $this->assertIsString($data['checked_at']);
        // Should be parseable as a datetime
        $parsed = new \DateTimeImmutable($data['checked_at']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $parsed);
    }

    public function testAutonomyEndpointConvergenceIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['convergence']);
    }

    public function testAutonomyEndpointLastActivityIsArray(): void
    {
        $this->client->request('GET', '/api/v1/monitoring/autonomy', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['last_activity']);
    }
}
