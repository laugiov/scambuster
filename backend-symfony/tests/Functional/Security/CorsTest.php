<?php

declare(strict_types=1);

namespace Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CORS must never allow '*' on the authenticated API, and must echo only
 * origins in the configured allowlist (CORS_ALLOW_ORIGIN regex).
 */
final class CorsTest extends WebTestCase
{
    private const API_PATH = '/api/v1/scambaiting/stats';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPreflightFromAllowedOriginEchoesThatOrigin(): void
    {
        $this->client->request('OPTIONS', self::API_PATH, [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3002',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        self::assertSame(
            'http://localhost:3002',
            $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function testResponseNeverAllowsWildcardOrigin(): void
    {
        $this->client->request('GET', self::API_PATH, [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3002',
        ]);

        self::assertNotSame(
            '*',
            $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function testDisallowedOriginIsNeitherEchoedNorWildcarded(): void
    {
        $this->client->request('OPTIONS', self::API_PATH, [], [], [
            'HTTP_ORIGIN' => 'http://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $acao = $this->client->getResponse()->headers->get('Access-Control-Allow-Origin');
        self::assertNotSame('http://evil.example.com', $acao);
        self::assertNotSame('*', $acao);
    }
}
