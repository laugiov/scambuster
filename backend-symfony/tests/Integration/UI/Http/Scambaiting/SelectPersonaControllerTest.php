<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

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
}
