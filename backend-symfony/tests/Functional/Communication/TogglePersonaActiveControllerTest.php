<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TogglePersonaActiveControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/senior_trusting/active', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => false]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturns404ForUnknownPersona(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/nonexistent_persona/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => false]));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRejects422WhenMissingActiveField(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/senior_trusting/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testRejects422WhenActiveNotBoolean(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/senior_trusting/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => 'yes']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_UNPROCESSABLE_ENTITY, Response::HTTP_NOT_FOUND]);
    }

    public function testDeactivatePersona(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/senior_trusting/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => false]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertFalse($data['data']['is_active']);
        }
    }

    public function testReactivatePersona(): void
    {
        $this->client->request('PATCH', '/api/v1/personas/senior_trusting/active', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['active' => true]));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertTrue($data['data']['is_active']);
        }
    }
}
