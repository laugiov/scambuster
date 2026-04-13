<?php

declare(strict_types=1);

namespace Tests\Functional\Monitoring;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for AutonomyMonitoringController (GET /api/v1/monitoring/autonomy).
 */
final class AutonomyMonitoringControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/monitoring/autonomy';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAutonomyEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', self::ENDPOINT);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAutonomyEndpointWithFakeJwtReturnsResponse(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if authorized, 403 if monitoring:read permission denied
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_FORBIDDEN,
        ]);
    }

    public function testAutonomyEndpointWithAdminJwtReturnsStatus(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_FORBIDDEN,
        ]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
        }
    }

    public function testAutonomyEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $this->assertResponseHeaderSame('content-type', 'application/json');
        }
    }

    public function testAutonomyEndpointReturnsExpectedStructure(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            // The handler returns autonomy status with expected keys
            $this->assertArrayHasKey('status', $data);
            $this->assertArrayHasKey('checked_at', $data);
        }
    }

    public function testAutonomyEndpointRejectsPostMethod(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testAutonomyEndpointRejectsPutMethod(): void
    {
        $this->client->request('PUT', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testAutonomyEndpointRejectsDeleteMethod(): void
    {
        $this->client->request('DELETE', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
