<?php

declare(strict_types=1);

namespace Tests\EndToEnd\User;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MeControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testMeEndpointRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/me');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMeEndpointWithAuthReturnsUserInfo(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('roles', $data);
    }

    public function testMeEndpointResponseContainsIdField(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
    }

    public function testMeEndpointRolesIsArray(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data['roles']);
        $this->assertNotEmpty($data['roles']);
    }

    public function testMeEndpointEmailIsNotEmpty(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['email']);
    }

    public function testMeEndpointReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testMeEndpointWithAdminTokenReturnsAdminRole(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains('ROLE_ADMIN', $data['roles']);
    }

    public function testMeEndpointAllThreeFieldsPresent(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('roles', $data);
        // All three fields must be present
        $this->assertCount(3, $data, 'MeController response should have exactly 3 fields: id, email, roles');
    }

    public function testMeEndpointIdIsStringOrNull(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue(
            is_string($data['id']) || is_null($data['id']),
            'id should be a string or null'
        );
    }

    public function testMeEndpointRolesContainsRoleUser(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains('ROLE_USER', $data['roles']);
    }

    public function testMeEndpointReturnsHttp200(): void
    {
        $this->client->request('GET', '/api/v1/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }
}
