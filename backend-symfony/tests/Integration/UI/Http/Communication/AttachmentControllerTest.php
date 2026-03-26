<?php

declare(strict_types=1);

namespace Tests\Integration\UI\Http\Communication;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AttachmentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // ---- DELETE /api/v1/communication/attachment/{attachmentId} ----

    public function testDeleteAttachmentRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000001');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDeleteAttachmentReturns404ForUnknownId(): void
    {
        $this->client->request('DELETE', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000099', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Attachment not found', $data['error']);
    }

    public function testDeleteAttachmentReturnsJsonContentType(): void
    {
        $this->client->request('DELETE', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000099', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ---- GET /api/v1/communication/attachment/{attachmentId}/download ----

    public function testDownloadAttachmentRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000001/download');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testDownloadAttachmentReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/v1/communication/attachment/00000000-0000-0000-0000-000000000099/download', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Attachment not found', $data['error']);
    }

    // ---- GET /api/v1/communication/attachment/conversation/{convId}/attachments ----

    public function testListConversationAttachmentsRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/v1/communication/attachment/conversation/00000000-0000-0000-0000-000000000001/attachments');

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testListConversationAttachmentsReturns404ForUnknownConversation(): void
    {
        $this->client->request('GET', '/api/v1/communication/attachment/conversation/00000000-0000-0000-0000-000000000099/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Conversation not found', $data['error']);
    }

    public function testListConversationAttachmentsReturnsJsonContentType(): void
    {
        $this->client->request('GET', '/api/v1/communication/attachment/conversation/00000000-0000-0000-0000-000000000099/attachments', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseHeaderSame('content-type', 'application/json');
    }
}
