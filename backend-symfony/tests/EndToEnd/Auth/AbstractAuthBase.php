<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class AbstractAuthBase extends WebTestCase
{
    protected ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $client = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /**
     * Helper to get a JWT for a regular user.
     */
    protected function getJwtToken(): string
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'user@example.com',
                'password' => 'Un1que$trongPassword2024'
            ])
        );
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['access_token'] ?? '';
    }

    /**
     * Helper to get a JWT for an admin.
     * Note: adapt according to your FakeAuthService if you want an admin role in test.
     */
    protected function getAdminJwtToken(): string
    {
        // FakeAuthService must return a special token if you want an admin (adapt according to your logic!)
        return 'fake-admin-jwt';
    }

    /**
     * Helper to get a CSRF token.
     */
    protected function getCsrfToken(): string
    {
        // If you have a dedicated endpoint or manager, adapt here
        // For many tests, we just use a fake token accepted by the test tokenManager
        return 'valid_csrf_token';
    }

    /**
     * Helper to provide a valid login payload.
     */
    protected function getValidLoginPayload(): array
    {
        return [
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024'
        ];
    }

    /**
     * Helper to make an authenticated POST with CSRF.
     */
    protected function postWithAuthAndCsrf(string $url, array $data = []): void
    {
        $data['jwt_token'] = 'fake-jwt';
        $this->client->request(
            'POST',
            $url,
            [],
            [],
            [
                'HTTP_X_CSRF_TOKEN' => $this->getCsrfToken(),
                'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
                'AUTHORIZATION' => 'Bearer fake-jwt',
                'CONTENT_TYPE' => 'application/json'
            ],
            json_encode($data)
        );
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('X-AUTH-TOKEN', 'fake-jwt'));
    }
}
