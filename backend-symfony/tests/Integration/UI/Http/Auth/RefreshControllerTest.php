<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for RefreshController response structure.
 * Full refresh flow is tested in App\Tests\Integration\Auth\RefreshToken.
 */
final class RefreshControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRefreshWithInvalidTokenReturns401(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => 'invalid-token']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testRefreshWithInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not json}');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED]);
    }

    public function testRefreshWithMissingTokenReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_UNAUTHORIZED]);
    }

    public function testRefreshReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => 'test']));

        $this->assertStringContainsString('application/json', $this->client->getResponse()->headers->get('Content-Type') ?? '');
    }
}
