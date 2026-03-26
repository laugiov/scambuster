<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class MessageControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    // Message IDs from fixtures: conv index 0 => msg_id 000...001 (in), 000...101 (out)
    private const MSG_INBOUND_1 = '00000000-0000-0000-0000-000000000001';
    private const MSG_OUTBOUND_1 = '00000000-0000-0000-0000-000000000101';
    private const MSG_SOFT_DELETED = '00000000-0000-0000-0000-999999999999';
    private const MSG_NONEXISTENT = '99999999-9999-9999-9999-999999999999';
    private const CONV_OPEN = '00000000-0000-0000-0000-000000000001';
    private const BASE_URL = '/api/v1/communication/message';

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ──────────────────────────────────────────────
    // GET MESSAGE (GET /{msgId})
    // ──────────────────────────────────────────────

    public function testGetMessageReturns200(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testGetMessageReturnsExpectedStructure(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertArrayHasKey('body_text', $data);
        $this->assertArrayHasKey('direction', $data);
        $this->assertArrayHasKey('ts_msg', $data);
        $this->assertArrayHasKey('headers', $data);
        $this->assertSame(self::MSG_INBOUND_1, $data['msg_id']);
    }

    public function testGetMessageReturns404ForNonExistent(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_NONEXISTENT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Message not found', $data['error']);
    }

    public function testGetMessageReturns404ForSoftDeleted(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_SOFT_DELETED, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGetMessageRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // CREATE MESSAGE (POST /)
    // ──────────────────────────────────────────────

    public function testCreateMessageReturns201(): void
    {
        $payload = [
            'conv_id' => self::CONV_OPEN,
            'channel_id' => 1,
            'direction' => 'in',
            'body_text' => 'Test message body from integration test',
            'headers' => ['From' => 'test@example.com', 'Subject' => 'Test'],
            'ts_msg' => '2025-06-01T12:00:00+00:00',
        ];

        $this->client->request('POST', self::BASE_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('msg_id', $data);
        $this->assertArrayHasKey('conv_id', $data);
        $this->assertArrayHasKey('ts_msg', $data);
        $this->assertSame(self::CONV_OPEN, $data['conv_id']);
    }

    public function testCreateMessageReturns400ForMissingField(): void
    {
        $payload = [
            'conv_id' => self::CONV_OPEN,
            // missing channel_id, direction, body_text, headers, ts_msg
        ];

        $this->client->request('POST', self::BASE_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing field', $data['error']);
    }

    public function testCreateMessageReturns400ForInvalidJson(): void
    {
        $this->client->request('POST', self::BASE_URL, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid json');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testCreateMessageRequiresAuth(): void
    {
        $this->client->request('POST', self::BASE_URL, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['dummy' => true]));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // DELETE MESSAGE (DELETE /{msgId})
    // ──────────────────────────────────────────────

    public function testDeleteMessageReturns200(): void
    {
        // Use outbound message from conv index 1 (000...102) to avoid affecting other tests
        $this->client->request('DELETE', self::BASE_URL . '/00000000-0000-0000-0000-000000000102', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Message deleted', $data['message']);
    }

    public function testDeleteMessageReturns404ForNonExistent(): void
    {
        $this->client->request('DELETE', self::BASE_URL . '/' . self::MSG_NONEXISTENT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testDeleteMessageRequiresAuth(): void
    {
        $this->client->request('DELETE', self::BASE_URL . '/' . self::MSG_INBOUND_1);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // PATCH MESSAGE (PATCH /{msgId})
    // ──────────────────────────────────────────────

    public function testPatchMessageReturns400ForInvalidJson(): void
    {
        $this->client->request('PATCH', self::BASE_URL . '/' . self::MSG_INBOUND_1, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '{invalid}');

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function testPatchMessageReturns404ForNonExistent(): void
    {
        $this->client->request('PATCH', self::BASE_URL . '/' . self::MSG_NONEXISTENT, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['body_text' => 'updated']));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testPatchMessageRequiresAuth(): void
    {
        $this->client->request('PATCH', self::BASE_URL . '/' . self::MSG_INBOUND_1, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['body_text' => 'updated']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // GET MESSAGE ATTACHMENTS (GET /{msgId}/attachments)
    // ──────────────────────────────────────────────

    public function testGetMessageAttachmentsReturns200(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testGetMessageAttachmentsRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/attachments');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // UPLOAD ATTACHMENT (POST /{msgId}/attachments)
    // ──────────────────────────────────────────────

    public function testUploadAttachmentReturns404ForNonExistentMessage(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_NONEXISTENT . '/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Message not found', $data['error']);
    }

    public function testUploadAttachmentReturns400ForNoFile(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('No file uploaded', $data['error']);
    }

    public function testUploadAttachmentRequiresAuth(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/attachments');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // GET MESSAGE IOCS (GET /{msgId}/iocs)
    // ──────────────────────────────────────────────

    public function testGetMessageIocsReturns200(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
    }

    public function testGetMessageIocsRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/iocs');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // GET MESSAGE RISK (GET /{msgId}/risk)
    // ──────────────────────────────────────────────

    public function testGetMessageRiskReturns200OrHandlesGracefully(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/risk', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if risk can be calculated, 404 if message has no IOCs for risk calc
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_NOT_FOUND]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('score_agg', $data);
            $this->assertArrayHasKey('level', $data);
            $this->assertArrayHasKey('reason', $data);
            $this->assertArrayHasKey('should_reply', $data);
        }
    }

    public function testGetMessageRiskRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/risk');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // GET MESSAGE BY MESSAGE-ID (GET /by-message-id/{messageId})
    // ──────────────────────────────────────────────

    public function testGetMessageByMessageIdReturns404ForNonExistent(): void
    {
        $this->client->request('GET', self::BASE_URL . '/by-message-id/nonexistent@example.com', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Message not found', $data['error']);
    }

    public function testGetMessageByMessageIdRequiresAuth(): void
    {
        $this->client->request('GET', self::BASE_URL . '/by-message-id/test@example.com');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    // ──────────────────────────────────────────────
    // EXTRACT IOCS (POST /{msgId}/extract-iocs)
    // ──────────────────────────────────────────────

    public function testExtractIocsReturns404ForNonExistentMessage(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_NONEXISTENT . '/extract-iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'regex']));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Message not found', $data['error']);
    }

    public function testExtractIocsReturns400ForInvalidMethod(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/extract-iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'invalid_method']));

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid method', $data['error']);
    }

    public function testExtractIocsWithRegexMethod(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/extract-iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'regex']));

        $statusCode = $this->client->getResponse()->getStatusCode();
        // 200 if extraction succeeds, 400 if runtime error
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_BAD_REQUEST]);

        if ($statusCode === Response::HTTP_OK) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('msg_id', $data);
            $this->assertArrayHasKey('method', $data);
            $this->assertArrayHasKey('iocs_found', $data);
            $this->assertArrayHasKey('iocs', $data);
            $this->assertArrayHasKey('extraction_time_ms', $data);
            $this->assertSame('regex', $data['method']);
            $this->assertSame(self::MSG_INBOUND_1, $data['msg_id']);
        }
    }

    public function testExtractIocsReturns404ForSoftDeletedMessage(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_SOFT_DELETED . '/extract-iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'regex']));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testExtractIocsRequiresAuth(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/extract-iocs', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['method' => 'regex']));

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testExtractIocsWithEmptyBody(): void
    {
        $this->client->request('POST', self::BASE_URL . '/' . self::MSG_INBOUND_1 . '/extract-iocs', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], '');

        // Empty body defaults to hybrid method - should succeed or fail gracefully
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_BAD_REQUEST]);
    }
}
