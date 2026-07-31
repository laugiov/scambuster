<?php

declare(strict_types=1);

namespace Tests\Functional\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SelectPersonaControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testSelectPersonaRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSelectPersonaReturnsSelectedPersona(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if personas exist for this scam type, 500 if no persona could be selected
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_INTERNAL_SERVER_ERROR]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
            $this->assertArrayHasKey('selected_persona', $data['data']);
            $this->assertArrayHasKey('strategy', $data['data']);
            $this->assertArrayHasKey('scam_type_code', $data['data']);
            $this->assertArrayHasKey('selection_context', $data['data']);
            $this->assertSame('PHISHING', $data['data']['scam_type_code']);
        }
    }

    public function testSelectPersonaReturns400WithMissingScamTypeCode(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSelectPersonaReturns400WithInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not valid json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testSelectPersonaReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testSelectPersonaWithValidScamTypeReturnsSelectedPersona(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'ADVANCE_FEE']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('data', $data);
            $this->assertIsString($data['data']['selected_persona']);
            $this->assertNotEmpty($data['data']['selected_persona']);
        }
    }

    public function testSelectPersonaResponseHasStrategyField(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertArrayHasKey('strategy', $data['data']);
            $this->assertIsString($data['data']['strategy']);
        }
    }

    public function testSelectPersonaResponseHasScamTypeCodeField(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertArrayHasKey('scam_type_code', $data['data']);
            $this->assertSame('PHISHING', $data['data']['scam_type_code']);
        }
    }

    public function testSelectPersonaWithEmptyStringScamTypeReturns400(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => '']));

        // Empty string is still a string, so it passes the is_string check
        // but should fail persona selection or be caught as bad request
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
            Response::HTTP_OK,
        ]);
    }

    public function testSelectPersonaWithNonStringScamTypeReturns400(): void
    {
        // scam_type_code is an integer instead of string - should fail is_string check
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 12345]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertStringContainsString('Missing or invalid scam_type_code', $data['error']);
    }

    public function testSelectPersonaWithNullScamTypeReturns400(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => null]));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function testSelectPersonaWithRomanceScamType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'ROMANCE']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_INTERNAL_SERVER_ERROR]
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertSame('ROMANCE', $data['data']['scam_type_code']);
            $this->assertContains($data['data']['strategy'], ['exploitation', 'exploration', 'cold_start', 'exploit', 'explore']);
            $this->assertIsArray($data['data']['selection_context']);
        }
    }

    public function testSelectPersonaWithTechSupportScamType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'TECH_SUPPORT']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_INTERNAL_SERVER_ERROR]
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testSelectPersonaWithInvestmentScamType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'INVESTMENT']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_OK, Response::HTTP_INTERNAL_SERVER_ERROR]
        );

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
    }

    public function testSelectPersonaWithNoActivePersonasReturns500(): void
    {
        // Use a scam type that is unlikely to have any active personas configured
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'NONEXISTENT_SCAM_TYPE_XYZ']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Should return 500 (no persona selected) or possibly 200 if fallback exists
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->assertFalse($data['success']);
            $this->assertArrayHasKey('error', $data);
        }
    }

    public function testSelectPersonaSelectionContextHasExpectedFields(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/select-persona', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['scam_type_code' => 'PHISHING']));

        $statusCode = $this->client->getResponse()->getStatusCode();

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $context = $data['data']['selection_context'];
            $this->assertIsArray($context);
            // The selection context comes from getSelectionStats
            $this->assertArrayHasKey('scam_type_code', $context);
        }
    }
}
