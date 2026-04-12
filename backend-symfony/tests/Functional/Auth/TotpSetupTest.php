<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for TOTP setup endpoint.
 *
 * Note: The /api/v1/2fa/setup endpoint requires ROLE_USER, which in test
 * environment uses InMemoryUser via TestTokenAuthenticator. Since InMemoryUser
 * is not a Doctrine entity, the endpoint returns 404 (user not found in DB).
 * This test verifies the route is wired and auth-gated correctly.
 */
final class TotpSetupTest extends WebTestCase
{
    public function testSetupRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/2fa/setup', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        // Without auth token, should be rejected (401 or 403)
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]
        );
    }

    public function testSetupWithAuthReturnsUserNotFoundForInMemoryUser(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/2fa/setup', [], [], [
            'CONTENT_TYPE'       => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        // InMemoryUser is not a Doctrine User entity, so endpoint returns 404
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('User not found', $data['message']);
    }
}
