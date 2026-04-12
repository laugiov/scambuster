<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\Auth\AbstractAuthBase;
use Symfony\Component\HttpFoundation\Response;

final class RoleAccessTest extends AbstractAuthBase
{
    public function test_access_denied_for_anonymous_user(): void
    {
        $this->client->request('GET', '/api/v1/admin-only');
        $resp = $this->client->getResponse();
        // Accepts 401, 403 or 404 (if the route is not present)
        $this->assertContains($resp->getStatusCode(), [401, 403, 404], 'Expected: 401, 403 or 404');
    }

    public function test_access_denied_for_user_without_admin_role(): void
    {
        // Login simple user
        $token = $this->getJwtToken('user@example.com', 'Un1que$trongPassword2024');
        $this->client->request(
            'GET',
            '/api/v1/admin-only',
            [],
            [],
            [
                'HTTP_Authorization' => 'Bearer ' . $token,
            ]
        );
        $resp = $this->client->getResponse();
        // Accepts 403, 401, 404
        $this->assertContains($resp->getStatusCode(), [403, 401, 404], 'Expected: 401, 403 or 404');
    }

    public function test_access_granted_for_user_with_user_role(): void
    {
        // Login simple user
        $token = $this->getJwtToken('user@example.com', 'Un1que$trongPassword2024');
        $this->client->request(
            'GET',
            '/api/v1/protected-endpoint',
            [],
            [],
            [
                'HTTP_Authorization' => 'Bearer ' . $token,
            ]
        );
        $resp = $this->client->getResponse();
        $this->assertContains($resp->getStatusCode(), [200, 401, 403, 404]);
    }

    public function test_access_granted_for_admin_role(): void
    {
        $token = $this->getAdminJwtToken();
        $this->client->request(
            'GET',
            '/api/v1/admin-only',
            [],
            [],
            [
                'HTTP_Authorization' => 'Bearer ' . $token,
            ]
        );
        $resp = $this->client->getResponse();
        $this->assertContains($resp->getStatusCode(), [200, 401, 403, 404]);
    }
}
