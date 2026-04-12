<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\RateLimiter\RateLimiterFactory;

final class LoginRateLimitTest extends AbstractAuthBase
{
    public function test_it_blocks_after_too_many_attempts(): void
    {
        // Reset and pre-exhaust the limiter (test config: 100/min)
        $container = static::getContainer();
        /** @var RateLimiterFactory $factory */
        $factory = $container->get('limiter.login_ip');
        $limiter = $factory->create('127.0.0.1');
        $limiter->reset();

        // Consume 99 of 100 tokens
        $limiter->consume(99);

        // This request consumes the last token (100th) -- should return 401
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'badpassword'])
        );
        $this->assertResponseStatusCodeSame(401);

        // This request exceeds the limit (101st) -- should return 429
        $this->client->request(
            'POST',
            '/api/v1/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'user@example.com', 'password' => 'badpassword'])
        );
        $this->assertResponseStatusCodeSame(429);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('retry_after', $data);

        // Clean up
        $limiter->reset();
    }
}
