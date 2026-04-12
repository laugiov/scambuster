<?php

declare(strict_types=1);

namespace Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testAdminEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/admin');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAdminEndpointForbiddenForNonAdmin(): void
    {
        $this->client->request('GET', '/api/v1/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAdminEndpointReturnsWelcomeForAdmin(): void
    {
        $this->client->request('GET', '/api/v1/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Welcome, admin!', $data['message']);
    }

    public function testAdminEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/admin', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
