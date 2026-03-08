<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

final class MeControllerTest extends WebTestCase
{
    public function test_me_route_requires_authentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/me');
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
} 