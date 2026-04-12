<?php declare(strict_types=1);

namespace App\Tests\Functional\Auth;

final class RefreshTokenFailureTest extends AbstractAuthBase
{
    public function test_it_fails_with_invalid_refresh_token(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['refresh_token' => 'totally-invalid'])
        );
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('invalid', strtolower($data['message']));
    }

    public function test_it_fails_with_missing_refresh_token(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $this->assertResponseStatusCodeSame(400);
    }

    public function test_it_fails_with_expired_refresh_token(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/refresh',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['refresh_token' => 'expired-refresh'])
        );
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('expired', strtolower($data['message']));
    }
}
