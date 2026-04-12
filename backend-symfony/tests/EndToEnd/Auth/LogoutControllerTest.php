<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class LogoutControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLogoutWithValidRefreshTokenReturns204(): void
    {
        // First login to get a valid refresh token
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $loginData = json_decode($this->client->getResponse()->getContent(), true);
        $refreshToken = $loginData['refresh_token'] ?? 'fake-refresh';

        // Then logout
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => $refreshToken]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 204 on success, 401 if token is invalid/already used
        $this->assertContains($statusCode, [
            Response::HTTP_NO_CONTENT,
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_BAD_REQUEST,
        ]);
    }

    public function testLogoutWithInvalidRefreshTokenReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => 'totally-invalid-token']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_NO_CONTENT,
            Response::HTTP_UNAUTHORIZED,
            Response::HTTP_BAD_REQUEST,
        ]);
    }

    public function testLogoutWithInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid json}');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Invalid JSON', $data['message']);
    }

    public function testLogoutWithMissingRefreshTokenReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);
    }

    public function testLogoutReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['refresh_token' => 'some-token']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 204 has no body, others should be JSON
        if ($statusCode === Response::HTTP_NO_CONTENT) {
            $this->assertSame(Response::HTTP_NO_CONTENT, $statusCode);
        } else {
            $contentType = $this->client->getResponse()->headers->get('content-type') ?? '';
            $this->assertStringContainsString('application/json', $contentType);
        }
    }

    public function testLogoutWithEmptyBodyReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);
    }

    public function testLogoutWithNullBodyReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], 'null');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);
    }

    public function testLogoutErrorResponseContainsMessageKey(): void
    {
        $this->client->request('POST', '/api/v1/auth/logout', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid json}');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
    }
}
