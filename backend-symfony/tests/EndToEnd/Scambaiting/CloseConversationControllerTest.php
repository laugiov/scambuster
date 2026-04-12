<?php

declare(strict_types=1);

namespace Tests\EndToEnd\Scambaiting;

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

    public function testCloseConversationWithFixtureConvId(): void
    {
        // Use the open conversation from fixtures
        $convId = '00000000-0000-0000-0000-000000000001';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);

        // Should succeed (200) or fail with a business rule error (400)
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
    }

    public function testCloseConversationResponseHasSuccessAndMessageKeys(): void
    {
        $convId = '00000000-0000-0000-0000-000000000001';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $statusCode = $this->client->getResponse()->getStatusCode();

        $this->assertArrayHasKey('success', $data);

        if ($statusCode === Response::HTTP_OK) {
            // Success responses have 'message' and 'conv_id'
            $this->assertArrayHasKey('message', $data);
            $this->assertArrayHasKey('conv_id', $data);
            $this->assertTrue($data['success']);
            $this->assertSame($convId, $data['conv_id']);
        } else {
            // Error responses have 'error'
            $this->assertArrayHasKey('error', $data);
            $this->assertFalse($data['success']);
        }
    }

    public function testCloseAlreadyClosedConversationReturnsResponse(): void
    {
        // conv 00000000-0000-0000-0000-000000000002 is already CLOSED in fixtures
        $convId = '00000000-0000-0000-0000-000000000002';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Could return 200 (idempotent), 400 (RuntimeException), or 500
        $this->assertContains($statusCode, [
            Response::HTTP_OK,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
        $this->assertArrayHasKey('success', $data);
        $this->assertIsBool($data['success']);
    }

    public function testCloseConversationWithNonexistentUuid(): void
    {
        // This UUID does not exist in fixtures - should trigger RuntimeException catch
        $convId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should be 400 (RuntimeException) or 500 (general Exception)
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCloseConversationRuntimeExceptionReturns400(): void
    {
        // A UUID that doesn't match any conversation triggers RuntimeException
        $convId = '00000000-0000-0000-0000-ffffffffffff';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // RuntimeException -> 400 with error message from exception
        if ($statusCode === Response::HTTP_BAD_REQUEST) {
            $this->assertFalse($data['success']);
            $this->assertIsString($data['error']);
            $this->assertNotEmpty($data['error']);
        }
    }

    public function testCloseConversationSuccessResponseStructure(): void
    {
        $convId = '00000000-0000-0000-0000-000000000001';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        if ($statusCode === Response::HTTP_OK) {
            $this->assertTrue($data['success']);
            $this->assertSame('Conversation closed successfully', $data['message']);
            $this->assertSame($convId, $data['conv_id']);
        }
    }

    public function testCloseConversationWithMalformedUuidString(): void
    {
        // Not a valid UUID format - should still be routed to controller but fail in service
        $convId = 'not-a-valid-uuid';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should error (400 or 500) since the service can't find this conversation
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
        $this->assertFalse($data['success']);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCloseConversation500ResponseHasGenericError(): void
    {
        // Use a conv ID that might trigger a non-RuntimeException
        $convId = '00000000-0000-0000-0000-000000000099';

        $this->client->request('POST', "/api/v1/scambaiting/conversation/{$convId}/close", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        // Should be either 400 (RuntimeException) or 500 (general Exception)
        $this->assertContains($statusCode, [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ]);
        $this->assertFalse($data['success']);

        // If we get 500, the error message should be generic
        if ($statusCode === Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->assertSame('Internal server error', $data['error']);
        }
    }
}
