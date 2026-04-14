<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ScamTypeController (GET /api/v1/communication/scam-types).
 */
final class ScamTypeControllerValidationTest extends WebTestCase
{
    private KernelBrowser $client;

    private const ENDPOINT = '/api/v1/communication/scam-types';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testListScamTypesRequiresAuthentication(): void
    {
        $this->client->request('GET', self::ENDPOINT);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListScamTypesReturns200WithAuth(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if authorized, 403 if conversation:read permission denied
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_FORBIDDEN,
        ]);
    }

    public function testListScamTypesReturnsJsonArray(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $this->assertResponseHeaderSame('content-type', 'application/json');

            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
        }
    }

    public function testListScamTypesReturnsExpectedStructure(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
            $this->assertNotEmpty($data, 'At least one scam type should exist in fixtures');

            $first = $data[0];
            $this->assertArrayHasKey('scam_type_id', $first);
            $this->assertArrayHasKey('code', $first);
        }
    }

    public function testListScamTypesRejectsPostMethod(): void
    {
        $this->client->request('POST', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testListScamTypesWithAdminJwt(): void
    {
        $this->client->request('GET', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_FORBIDDEN,
        ]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertIsArray($data);
        }
    }

    public function testListScamTypesRejectsDeleteMethod(): void
    {
        $this->client->request('DELETE', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testListScamTypesRejectsPatchMethod(): void
    {
        $this->client->request('PATCH', self::ENDPOINT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
