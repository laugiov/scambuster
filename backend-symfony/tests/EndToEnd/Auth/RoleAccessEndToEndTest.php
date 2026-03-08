<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RoleAccessEndToEndTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    private function getUserJwt(): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    private function getAdminJwt(): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    public function test_it_denies_access_to_admin_endpoint_for_user(): void
    {
        $jwt = $this->getUserJwt();
        $this->client->request('GET', '/api/v1/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function test_it_allows_access_to_admin_endpoint_for_admin(): void
    {
        $jwt = $this->getAdminJwt();
        $this->client->request('GET', '/api/v1/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
        ]);
        $this->assertResponseIsSuccessful();
    }
}
