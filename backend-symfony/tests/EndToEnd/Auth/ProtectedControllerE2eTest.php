<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtectedControllerE2eTest extends WebTestCase
{
    public function test_user_can_access_some_protected_endpoint(): void
    {
        $client = static::createClient();
        // Login pour obtenir un vrai JWT
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'Un1que$trongPassword2024'])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        $jwt = $data['access_token'] ?? null;
        $this->assertNotNull($jwt);

        $client->request(
            'POST',
            '/api/v1/some-protected-endpoint',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                'HTTP_X_CSRF_TOKEN' => 'valid_csrf_token',
            ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('OK', $data['message']);
    }

    public function test_admin_can_access_admin_protected_endpoint(): void
    {
        $client = static::createClient();
        // Login admin pour obtenir un vrai JWT
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'admin@example.com', 'password' => 'Un1que$trongPassword2024'])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        $jwt = $data['access_token'] ?? null;
        $this->assertNotNull($jwt);

        $client->request(
            'POST',
            '/api/v1/admin-protected-endpoint',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                'HTTP_X_CSRF_TOKEN' => 'valid_csrf_token',
            ]
        );
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('ADMIN OK', $data['message']);
    }

    public function test_user_cannot_access_admin_protected_endpoint(): void
    {
        $client = static::createClient();
        // Login user pour obtenir un vrai JWT
        $client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'Un1que$trongPassword2024'])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        $jwt = $data['access_token'] ?? null;
        $this->assertNotNull($jwt);

        $client->request(
            'POST',
            '/api/v1/admin-protected-endpoint',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
                'HTTP_X_CSRF_TOKEN' => 'valid_csrf_token',
            ]
        );
        $this->assertContains($client->getResponse()->getStatusCode(), [403, 401]);
    }
} 