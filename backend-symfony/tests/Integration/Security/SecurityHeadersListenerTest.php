<?php

declare(strict_types=1);

namespace App\Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityHeadersListenerTest extends WebTestCase
{
    public function testHealthzResponseContainsSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/healthz');

        $this->assertResponseIsSuccessful();

        $response = $client->getResponse();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('geolocation=()', $response->headers->get('Permissions-Policy') ?? '');
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy') ?? '');
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy') ?? '');
        $this->assertStringContainsString('max-age=31536000', $response->headers->get('Strict-Transport-Security') ?? '');
    }

    public function testApiEndpointContainsSecurityHeaders(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'user@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));

        $response = $client->getResponse();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testSecurityHeadersPresentOn404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/nonexistent');

        $response = $client->getResponse();
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }
}
