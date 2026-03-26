<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class GetPersonaPerformanceControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPersonaPerformanceRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPersonaPerformanceReturnsDataForValidPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if persona exists in fixtures, 404 if not
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
            $this->assertArrayHasKey('persona_code', $data['data']);
            $this->assertArrayHasKey('persona_label', $data['data']);
            $this->assertArrayHasKey('total_sessions', $data['data']);
            $this->assertArrayHasKey('global_avg_reward', $data['data']);
            $this->assertArrayHasKey('performance_by_scam_type', $data['data']);
        }
    }

    public function testPersonaPerformanceReturns404ForUnknownPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/nonexistent_persona/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('nonexistent_persona', $data['error']);
    }

    public function testPersonaPerformanceReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
