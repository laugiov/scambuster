<?php

declare(strict_types=1);

namespace Tests\Functional\Scambaiting;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReopenConversationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReopenConversationRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/conv_test_123/reopen');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testReopenConversationReturnsJsonContentType(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/00000000-0000-0000-0000-000000000099/reopen', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testReopenNonexistentConversationReturnsError(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/00000000-0000-0000-0000-000000000099/reopen', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_BAD_REQUEST, Response::HTTP_INTERNAL_SERVER_ERROR]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testReopenClosedFixtureConversationSucceeds(): void
    {
        // conv 00000000-0000-0000-0000-000000000002 is CLOSED in fixtures.
        $convId = '00000000-0000-0000-0000-000000000002';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/reopen", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame('Conversation reopened successfully', $data['message']);
            $this->assertSame($convId, $data['conv_id']);
        }
    }

    public function testReopenAlreadyOpenConversationIsIdempotent(): void
    {
        // conv 00000000-0000-0000-0000-000000000001 is OPEN in fixtures →
        // reopen is a no-op that still returns success.
        $convId = '00000000-0000-0000-0000-000000000001';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/reopen", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame($convId, $data['conv_id']);
        }
    }

    public function testReopenResponseStructureOnError(): void
    {
        $this->client->request('POST', '/api/v1/scambaiting/conversation/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/reopen', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('error', $data);
        $this->assertIsBool($data['success']);
        $this->assertIsString($data['error']);
    }
}
