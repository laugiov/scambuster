<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LogoutEndToEndTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function test_it_invalidates_refresh_token_on_logout(): void
    {
        // 1. Login for obtaining a refresh_token
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $refreshToken = $data['refresh_token'] ?? null;
        $this->assertNotEmpty($refreshToken);

        // 2. Logout (must return 204)
        $this->client->request('POST', '/api/v1/auth/logout', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'refresh_token' => $refreshToken,
        ]));
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // 3. Reuse of the refresh_token: should be rejected (refresh token invalidated)
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'refresh_token' => $refreshToken,
        ]));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid refresh token', $data['message']);
    }
}
