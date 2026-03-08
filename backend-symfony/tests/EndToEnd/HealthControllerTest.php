<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HealthControllerTest extends WebTestCase
{
    public function testHealthCheckReturnsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');
        $this->assertJsonStringEqualsJsonString(
            json_encode(['status' => 'ok']),
            $client->getResponse()->getContent()
        );
    }
} 