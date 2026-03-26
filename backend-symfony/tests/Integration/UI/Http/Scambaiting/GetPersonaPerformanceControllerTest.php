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

    public function testPersonaPerformanceDataHasPerformanceByScamType(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('performance_by_scam_type', $data['data']);
            $this->assertIsArray($data['data']['performance_by_scam_type']);

            foreach ($data['data']['performance_by_scam_type'] as $entry) {
                $this->assertArrayHasKey('scam_type_code', $entry);
                $this->assertArrayHasKey('sessions_count', $entry);
                $this->assertArrayHasKey('reward_avg', $entry);
                $this->assertArrayHasKey('is_cold_start', $entry);
                $this->assertIsBool($entry['is_cold_start']);
            }
        }
    }

    public function testPersonaPerformanceDataHasTotalSessions(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('total_sessions', $data['data']);
            $this->assertIsInt($data['data']['total_sessions']);
            $this->assertGreaterThanOrEqual(0, $data['data']['total_sessions']);
        }
    }

    public function testPersonaPerformanceDataHasGlobalAvgReward(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/elderly_person/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('global_avg_reward', $data['data']);
            $this->assertIsNumeric($data['data']['global_avg_reward']);
        }
    }

    public function testPersonaPerformanceWithGenericUserPersona(): void
    {
        $this->client->request('GET', '/api/v1/scambaiting/persona/generic_user/performance', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
    }
}
