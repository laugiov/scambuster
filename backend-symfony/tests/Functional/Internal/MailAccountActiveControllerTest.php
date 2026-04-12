<?php

declare(strict_types=1);

namespace Tests\Functional\Internal;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MailAccountActiveControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/active');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEndpointRequiresAdminRole(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testEndpointReturnsArrayWithAdminAuth(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testResponseIsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testResponseItemsHaveExpectedFields(): void
    {
        $this->client->request('GET', '/api/v1/internal/mail-account/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);

        if (count($data) > 0) {
            $first = $data[0];
            $this->assertArrayHasKey('account_id', $first);
            $this->assertArrayHasKey('protocol', $first);
            $this->assertArrayHasKey('endpoint', $first);
            $this->assertArrayHasKey('login_hash', $first);
        } else {
            // Empty array is valid when no active IMAP accounts exist
            $this->assertSame([], $data);
        }
    }
}
