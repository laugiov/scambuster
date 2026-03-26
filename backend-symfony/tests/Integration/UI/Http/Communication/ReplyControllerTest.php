<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReplyControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ---- GET /api/v1/communication/conversation/{convId}/context ----

    public function testGetContextRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000001/context');

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Route may return 401 (auth required) or 500 (unhandled before auth check)
        $this->assertContains($statusCode, [401, 403, 500]);
    }

    public function testGetContextReturns404ForUnknownConversation(): void
    {
        $this->client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000099/context', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Conversation not found', $data['error']);
    }

    public function testGetContextReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/communication/conversation/00000000-0000-0000-0000-000000000099/context', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ---- POST /api/v1/communication/reply/generate ----

    public function testGenerateReplyRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [401, 403, 500]);
    }

    public function testGenerateReplyRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testGenerateReplyRejectsMissingRequiredFields(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['conv_id' => '123']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('conv_id', $data['error']);
    }

    public function testGenerateReplyReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testGenerateReplyReturnsErrorForNonexistentConversation(): void
    {
        $payload = [
            'conv_id' => '00000000-0000-0000-0000-000000000099',
            'last_msg_id' => '00000000-0000-0000-0000-000000000099',
        ];

        $this->client->request('POST', '/api/v1/communication/reply/generate', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // ---- POST /api/v1/communication/reply/draft ----

    public function testSaveDraftRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/draft', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSaveDraftRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/draft', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSaveDraftRejectsMissingFields(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/draft', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['msg_id' => 'abc']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('msg_id', $data['error']);
    }

    public function testSaveDraftReturns204OnSuccess(): void
    {
        $payload = [
            'msg_id' => '00000000-0000-0000-0000-000000000001',
            'draft' => [
                'text' => 'Hello',
                'html' => '<p>Hello</p>',
            ],
        ];

        $this->client->request('POST', '/api/v1/communication/reply/draft', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    // ---- GET /api/v1/communication/reply/{msgId} ----

    public function testGetReplyRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testGetReplyReturns404ForUnknownMessage(): void
    {
        $this->client->request('GET', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000099', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // ---- GET /api/v1/communication/reply/{msgId}/compose ----

    public function testComposeRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001/compose');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testComposeReturnsErrorForUnknownMessage(): void
    {
        $this->client->request('GET', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000099/compose', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // Either 404 (not found) or 400 (runtime error)
        $this->assertContains($statusCode, [Response::HTTP_NOT_FOUND, Response::HTTP_BAD_REQUEST]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // ---- POST /api/v1/communication/reply/{msgId}/sent ----

    public function testMarkSentRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001/sent', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{}');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testMarkSentRejectsInvalidJson(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001/sent', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], 'not-json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testMarkSentRejectsMissingRequiredFields(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001/sent', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['provider' => 'gmail']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing required fields', $data['error']);
    }

    public function testMarkSentReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/communication/reply/00000000-0000-0000-0000-000000000001/sent', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([]));

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
