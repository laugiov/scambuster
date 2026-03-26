<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Internal;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MailAccountSecretControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/resolve-secret/nonexistent-hash');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEndpointRequiresAdminRole(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/resolve-secret/nonexistent-hash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testNonExistentHashReturns404(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/resolve-secret/nonexistent-hash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testResponseIsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/resolve-secret/nonexistent-hash', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
