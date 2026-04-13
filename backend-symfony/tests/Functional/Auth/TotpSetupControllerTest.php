<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for TOTP setup controller (POST /api/v1/2fa/setup).
 *
 * Covers auth requirement, response structure, and method restrictions.
 * Note: InMemoryUser used in test env is not a Doctrine entity,
 * so the endpoint returns 404 ("User not found") for authenticated requests.
 */
final class TotpSetupControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/2fa/setup';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSetupRequiresAuthentication(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN]
        );
    }

    public function testSetupWithFakeJwtReturnsUserNotFound(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        // InMemoryUser is not a Doctrine User entity, so endpoint returns 404
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('User not found', $data['message']);
    }

    public function testSetupReturnsJsonContentType(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testSetupRejectsGetMethod(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testSetupWithAdminJwtReturnsUserNotFound(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        // Admin InMemoryUser is also not a Doctrine entity
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('User not found', $data['message']);
    }

    public function testSetupResponseStructureOnNotFound(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('message', $data);
        $this->assertIsString($data['message']);
    }
}
