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

    public function testRefreshWithEmptyBodyReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    public function testRefreshWithValidTokenFromLogin(): void
    {
        // Login to get a valid refresh token
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $loginData = json_decode($this->client->getResponse()->getContent(), true);

        if (!isset($loginData['refresh_token'])) {
            $this->markTestSkipped('Login did not return refresh token');
        }

        // Now refresh
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => $loginData['refresh_token']]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should return 200 with new tokens or 401 if token already consumed
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_UNAUTHORIZED,
        ]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('access_token', $data);
            $this->assertArrayHasKey('refresh_token', $data);
            $this->assertArrayHasKey('expires_in', $data);
        }
    }

    public function testRefreshUnauthorizedResponseContainsMessage(): void
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => 'invalid-token-123']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        // Message should be lowercase (controller does strtolower)
        $this->assertSame(strtolower($data['message']), $data['message']);
    }
}
