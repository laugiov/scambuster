<?php declare(strict_types=1);

namespace App\Tests\Integration\Auth;

final class LoginRateLimitTest extends AbstractAuthBase
{
    public function test_it_blocks_after_too_many_attempts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->client->request(
                'POST',
                '/api/v1/auth/login',
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['email' => 'user@example.com', 'password' => 'badpassword'])
            );
        }
        $this->assertResponseStatusCodeSame(429);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('retry_after', $data);
    }
}
