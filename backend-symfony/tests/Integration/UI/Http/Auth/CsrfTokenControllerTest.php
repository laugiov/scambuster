<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Auth;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CsrfTokenControllerTest extends WebTestCase
{
    public function testCsrfTokenReturnsToken(): void
    {
        $client = static::createClient();
        $client->request('GET', '/csrf-token');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $content = $client->getResponse()->getContent();
        $this->assertIsString($content);
        $data = json_decode($content, true);
        $this->assertArrayHasKey('csrf_token', $data);
        $this->assertNotEmpty($data['csrf_token']);
    }
}
