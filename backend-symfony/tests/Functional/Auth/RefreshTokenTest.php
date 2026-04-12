<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\Auth\AbstractAuthBase;
use Symfony\Component\HttpFoundation\Response;

final class RefreshTokenTest extends AbstractAuthBase
{
    public function test_it_refreshes_token_successfully(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'Un1que$trongPassword2024'])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $refreshToken = $data['refresh_token'] ?? null;
        $this->assertNotEmpty($refreshToken, 'The refresh_token must be present');

        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['refresh_token' => $refreshToken])
        );

        $resp = $this->client->getResponse();
        $this->assertTrue(
            \in_array($resp->getStatusCode(), [Response::HTTP_OK, 200]),
            'The expected response must be HTTP 200'
        );
        $refreshedData = json_decode($resp->getContent(), true);
        $this->assertArrayHasKey('access_token', $refreshedData);
        $this->assertNotEmpty($refreshedData['access_token']);
    }
}
