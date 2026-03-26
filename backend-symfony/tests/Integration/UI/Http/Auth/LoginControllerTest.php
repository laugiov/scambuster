<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for LoginController.
 *
 * Note: Basic login success/failure tests already exist in
 * App\Tests\Integration\Auth\LoginSuccessTest and LoginFailureTest.
 * These tests focus on response structure and content-type validation.
 */
final class LoginControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testLoginSuccessReturnsExpectedJsonStructure(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertNotEmpty($data['access_token']);
        $this->assertNotEmpty($data['refresh_token']);
        $this->assertIsInt($data['expires_in']);
    }

    public function testLoginFailureReturnsUnauthorized(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'wrong@example.com',
            'password' => 'wrongpassword',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
    }

    public function testLoginWithInvalidJsonReturns400(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid json}');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Invalid JSON', $data['message']);
    }

    public function testLoginReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testLoginWithEmptyBodyReturns400(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        ]);
    }

    public function testLoginWithMissingPasswordReturnsValidationError(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['email' => 'user@example.com']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Missing password should trigger validation error (422) or auth failure (401)
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    public function testLoginWithMissingEmailReturnsValidationError(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => 'SomePassword123!']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            Response::HTTP_UNAUTHORIZED,
        ]);
    }

    public function testLoginSuccessResponseContainsAllFields(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsString($data['access_token']);
        $this->assertIsString($data['refresh_token']);
        $this->assertIsInt($data['expires_in']);
        $this->assertGreaterThan(0, $data['expires_in']);
    }

    public function testLoginFailureMessageIsLowercase(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email' => 'wrong@example.com',
            'password' => 'wrongpassword',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        // LoginController lowercases the error message
        $this->assertSame(strtolower($data['message']), $data['message']);
    }
}
