<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LoginEndToEndTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function test_it_returns_jwt_on_successful_login(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    public function test_it_returns_401_on_invalid_password(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'wrongpassword',
        ]));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('invalid credentials.', $data['message']);
    }
}
