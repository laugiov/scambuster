<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CsrfTokenControllerTest extends WebTestCase
{
    public function testCsrfTokenEndpointReturnsToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/csrf-token');

        $this->assertResponseIsSuccessful();
        $this->assertResponseFormatSame('json');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('csrf_token', $data);
        $this->assertIsString($data['csrf_token']);
        $this->assertNotEmpty($data['csrf_token']);
    }
} 