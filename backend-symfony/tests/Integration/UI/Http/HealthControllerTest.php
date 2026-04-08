<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthzReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertSame('ok', $data['status']);
    }

    public function testHealthzDoesNotRequireAuth(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        // Should NOT be 401
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
