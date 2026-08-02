<?php

declare(strict_types=1);

namespace Tests\Functional\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operational endpoints (/api/metrics, /api/health) leak counts, kill-switch
 * state and version, so they must require an admin. The bare /healthz liveness
 * probe stays public.
 */
final class MetricsHealthAccessTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    /** @return array<string, array{string}> */
    public static function protectedEndpoints(): array
    {
        return [
            'metrics' => ['/api/metrics'],
            'health' => ['/api/health'],
        ];
    }

    /** @dataProvider protectedEndpoints */
    public function testAnonymousIsRejected(string $path): void
    {
        $this->client->request('GET', $path);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(Response::HTTP_OK, $status, "$path must not be public");
        self::assertContains($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]);
    }

    /** @dataProvider protectedEndpoints */
    public function testNonAdminIsForbidden(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    /** @dataProvider protectedEndpoints */
    public function testAdminIsAllowed(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt']);

        self::assertResponseIsSuccessful();
    }

    public function testHealthzLivenessStaysPublic(): void
    {
        $this->client->request('GET', '/healthz');

        self::assertResponseIsSuccessful();
    }
}
