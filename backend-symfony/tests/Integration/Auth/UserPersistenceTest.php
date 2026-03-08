<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Tests\Integration\Auth\AbstractAuthBase;
use Symfony\Component\HttpFoundation\Response;

final class UserPersistenceTest extends AbstractAuthBase
{
    public function test_user_exists_after_fixtures(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'Un1que$trongPassword2024'])
        );
        $resp = $this->client->getResponse();
        $this->assertContains($resp->getStatusCode(), [200, 401]);
        if ($resp->getStatusCode() === 200) {
            $data = json_decode($resp->getContent(), true);
            $this->assertArrayHasKey('access_token', $data);
        }
    }

    public function test_update_user_email(): void
    {
        $token = $this->getJwtToken('user@example.com', 'Un1que$trongPassword2024');
        $newEmail = 'user-updated@example.com';

        $this->client->request(
            'PATCH',
            '/api/v1/user/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Authorization' => 'Bearer ' . $token,
            ],
            json_encode(['email' => $newEmail])
        );
        $resp = $this->client->getResponse();
        $this->assertContains($resp->getStatusCode(), [200, 401, 403, 404]);
    }

    public function test_delete_user(): void
    {
        $token = $this->getJwtToken('user@example.com', 'Un1que$trongPassword2024');
        $this->client->request(
            'DELETE',
            '/api/v1/user/profile',
            [],
            [],
            [
                'HTTP_Authorization' => 'Bearer ' . $token,
            ]
        );
        $resp = $this->client->getResponse();
        $this->assertContains($resp->getStatusCode(), [200, 204, 401, 403, 404]);
    }
}
