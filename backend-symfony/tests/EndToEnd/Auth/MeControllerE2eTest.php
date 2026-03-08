<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MeControllerE2eTest extends WebTestCase
{
    public function test_me_route_requires_authentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function test_me_route_returns_user_info(): void
    {
        $client = static::createClient();
        // Login pour obtenir un JWT
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $data = json_decode($client->getResponse()->getContent(), true);
        $token = $data['access_token'] ?? null;
        $this->assertNotNull($token);

        // Call to the protected route
        $client->request('GET', '/api/v1/me', [], [], [
            'HTTP_Authorization' => 'Bearer ' . $token,
        ]);
        $this->assertResponseIsSuccessful();
        $me = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('user@example.com', $me['email']);
        $this->assertContains('ROLE_USER', $me['roles']);
        $this->assertNotEmpty($me['id']);
    }
} 