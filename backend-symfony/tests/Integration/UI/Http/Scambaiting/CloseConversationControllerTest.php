<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CloseConversationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCloseConversationRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/conv_test_123/close');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCloseConversationReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/00000000-0000-0000-0000-000000000099/close', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testCloseConversationReturnsErrorForNonexistentConversation(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/00000000-0000-0000-0000-000000000099/close', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // RuntimeException -> 400, other Exception -> 500
        $this->assertContains($statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_INTERNAL_SERVER_ERROR]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('success', $data);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCloseConversationResponseStructureOnError(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/00000000-0000-0000-0000-000000000099/close', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        // Error responses always have 'success' and 'error' keys
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('error', $data);
        $this->assertIsBool($data['success']);
        $this->assertIsString($data['error']);
    }
}
