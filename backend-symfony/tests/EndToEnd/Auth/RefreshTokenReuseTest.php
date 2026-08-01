<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * End-to-end proof of refresh-token reuse detection + family revocation.
 *
 * Runs against the REAL AuthService (the e2e env does not swap in FakeAuthService), so this
 * exercises the hardened rotation path over HTTP: hash-at-rest, family-scoped rotation, and
 * automatic reuse detection.
 */
final class RefreshTokenReuseTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    public function test_replaying_a_rotated_token_revokes_the_whole_family(): void
    {
        // Login → T1.
        $t1 = $this->login();

        // Rotate: T1 → T2 (200).
        $t2 = $this->refresh($t1);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'First rotation must succeed');
        $this->assertNotEmpty($t2);
        $this->assertNotSame($t1, $t2, 'Rotation must return a different refresh token');

        // Replay the already-rotated T1 → reuse detected → 401.
        $this->refresh($t1);
        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            'Replaying a rotated token must be denied',
        );

        // Family revoked: the legitimately-rotated T2 is now dead too → 401.
        $this->refresh($t2);
        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            'Reuse must revoke the whole family, so the live token T2 is also rejected',
        );
    }

    public function test_normal_rotation_chain_keeps_working(): void
    {
        // A clean chain (no replay) must keep rotating — reuse detection must not false-positive.
        $t1 = $this->login();
        $t2 = $this->refresh($t1);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $t3 = $this->refresh($t2);
        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $this->assertNotEmpty($t3);
        $this->assertNotSame($t2, $t3);
    }

    private function login(): string
    {
        $this->client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $token = $data['refresh_token'] ?? '';
        $this->assertNotEmpty($token, 'login must return a refresh_token');

        return $token;
    }

    /**
     * @return string the new refresh token on success, '' otherwise
     */
    private function refresh(string $refreshToken): string
    {
        $this->client->request('POST', '/api/v1/auth/refresh', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'refresh_token' => $refreshToken,
        ]));
        $data = json_decode($this->client->getResponse()->getContent(), true);

        return \is_array($data) ? ($data['refresh_token'] ?? '') : '';
    }
}
