<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use Symfony\Component\HttpFoundation\Response;

/**
 * Integration tests for TOTP login flow.
 *
 * Verifies backward compatibility: users without TOTP enabled
 * continue to receive tokens normally from the login endpoint.
 */
final class TotpLoginTest extends AbstractAuthBase
{
    /**
     * Login without TOTP enabled works normally (backward compatible).
     * Since test users are InMemoryUser (not Doctrine entities), the 2FA
     * check in LoginController finds no User entity and skips 2FA.
     */
    public function testLoginWithoutTotpReturnsTokensNormally(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($this->getValidLoginPayload()));

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertArrayHasKey('expires_in', $data);
        $this->assertArrayNotHasKey('requires_2fa', $data);
    }

    /**
     * The 2FA login endpoint exists and rejects invalid TOTP code format.
     */
    public function testTotpLoginRejectsInvalidCodeFormat(): void
    {
        $this->client->request('POST', '/api/v1/auth/2fa/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
            'code'     => 'abc',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('Invalid TOTP code format', $data['message']);
    }

    /**
     * The 2FA login endpoint rejects requests when user has no TOTP configured.
     * FakeAuthService authenticates successfully, but InMemoryUser has no TOTP.
     */
    public function testTotpLoginRejectsWhenTotpNotConfigured(): void
    {
        $this->client->request('POST', '/api/v1/auth/2fa/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
            'code'     => '123456',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('TOTP not configured for this account', $data['message']);
    }

    /**
     * The 2FA login endpoint rejects invalid credentials.
     */
    public function testTotpLoginRejectsInvalidCredentials(): void
    {
        $this->client->request('POST', '/api/v1/auth/2fa/login', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'email'    => 'wrong@example.com',
            'password' => 'wrongpassword',
            'code'     => '123456',
        ]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
