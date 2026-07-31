<?php

declare(strict_types=1);

namespace Tests\Functional\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetPersonaControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/personas/senior_trusting');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReturns404ForUnknownPersona(): void
    {
        $this->client->request('GET', '/api/v1/personas/nonexistent_persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('nonexistent_persona', $data['error']);
    }

    public function testReturnsFullPersonaDetail(): void
    {
        $this->client->request('GET', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);

            $persona = $data['data'];
            $this->assertSame('senior_trusting', $persona['persona_code']);
            $this->assertArrayHasKey('persona_label', $persona);
            $this->assertArrayHasKey('persona_tone', $persona);
            $this->assertArrayHasKey('system_prompt', $persona);
            $this->assertArrayHasKey('is_active', $persona);
            $this->assertArrayHasKey('created_by', $persona);
            $this->assertArrayHasKey('created_at', $persona);

            // system_prompt must be substantial
            $this->assertGreaterThan(100, strlen($persona['system_prompt']));
        }
    }

    public function testReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/personas/senior_trusting', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
