<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProtectedEndpointEndToEndTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    private function getValidJwt(): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    public function test_it_denies_access_without_jwt(): void
    {
        $this->client->request('POST', '/api/v1/some-protected-endpoint');
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Full authentication is required', $data['message']);
    }

    public function test_it_allows_access_with_valid_jwt_and_csrf(): void
    {
        $jwt = $this->getValidJwt();
        $this->client->request(
            'POST',
            '/api/v1/some-protected-endpoint',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                'HTTP_X_CSRF_TOKEN' => 'valid_csrf_token'
            ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('OK', $data['message']);
    }
}
