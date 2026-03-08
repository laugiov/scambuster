<?php

namespace App\Tests\Integration\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckTest extends WebTestCase
{
    public function test_healthcheck_returns_ok(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');
        $this->assertResponseIsSuccessful();
    }
}
