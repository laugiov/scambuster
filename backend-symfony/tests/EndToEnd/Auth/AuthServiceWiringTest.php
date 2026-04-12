<?php

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthServiceWiringTest extends WebTestCase
{
    public function test_fake_auth_service_is_used()
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $auth = $container->get(\App\Application\Auth\AuthServiceInterface::class);
        $this->assertSame(
            \App\Application\Auth\FakeAuthService::class,
            get_class($auth),
            "FakeAuthService should be injected in test environment"
        );
    }
}
