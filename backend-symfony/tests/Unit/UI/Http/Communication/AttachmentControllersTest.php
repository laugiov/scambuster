<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\AttachmentHandler;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\Conversation;
use App\UI\Http\Communication\DeleteAttachmentController;
use App\UI\Http\Communication\DownloadAttachmentController;
use App\UI\Http\Communication\ListConversationAttachmentsController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentControllersTest extends TestCase
{
    private AttachmentHandler&MockObject $handler;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(AttachmentHandler::class);
    }

    // --- DownloadAttachmentController ---

    public function test_download_returns_404_when_not_found(): void
    {
        $this->handler->method('getAttachment')->willReturn(null);
        $controller = new DownloadAttachmentController($this->handler);

        $response = $controller->__invoke('nonexistent-id');
        $this->assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Attachment not found', $data['error']);
    }

    public function test_download_returns_404_when_deleted(): void
    {
        $attachment = $this->createMock(Attachment::class);
        $attachment->method('getDeletedAt')->willReturn(new \DateTimeImmutable());

        $this->handler->method('getAttachment')->willReturn($attachment);
        $controller = new DownloadAttachmentController($this->handler);

        $response = $controller->__invoke('deleted-id');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_download_returns_501_when_storage_not_configured(): void
    {
        $attachment = $this->createMock(Attachment::class);
        $attachment->method('getDeletedAt')->willReturn(null);

        $this->handler->method('getAttachment')->willReturn($attachment);
        $controller = new DownloadAttachmentController($this->handler);

        $response = $controller->__invoke('valid-id');
        $this->assertSame(501, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('STORAGE_NOT_CONFIGURED', $data['code']);
    }

    // --- ListConversationAttachmentsController ---

    public function test_list_returns_404_when_conversation_not_found(): void
    {
        $this->handler->method('getConversation')->willReturn(null);
        $controller = new ListConversationAttachmentsController($this->handler);

        $response = $controller->__invoke('nonexistent-conv');
        $this->assertSame(404, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Conversation not found', $data['error']);
    }

    public function test_list_returns_empty_array_when_no_attachments(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $this->handler->method('getConversation')->willReturn($conversation);
        $this->handler->method('listConversationAttachments')->willReturn([]);

        $controller = new ListConversationAttachmentsController($this->handler);
        $response = $controller->__invoke('conv-1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('[]', $response->getContent());
    }

    public function test_list_returns_attachment_details(): void
    {
        $conversation = $this->createMock(Conversation::class);

        $att1 = $this->createMock(Attachment::class);
        $att1->method('getAttachmentId')->willReturn('att-1');
        $att1->method('getFilename')->willReturn('malware.pdf');
        $att1->method('getMimeType')->willReturn('application/pdf');
        $att1->method('getSizeBytes')->willReturn(12345);
        $att1->method('getDeletedAt')->willReturn(null);

        $att2 = $this->createMock(Attachment::class);
        $att2->method('getAttachmentId')->willReturn('att-2');
        $att2->method('getFilename')->willReturn('deleted.doc');
        $att2->method('getMimeType')->willReturn('application/msword');
        $att2->method('getSizeBytes')->willReturn(5000);
        $att2->method('getDeletedAt')->willReturn(new \DateTimeImmutable('2026-01-15 10:00:00'));

        $this->handler->method('getConversation')->willReturn($conversation);
        $this->handler->method('listConversationAttachments')->willReturn([$att1, $att2]);

        $controller = new ListConversationAttachmentsController($this->handler);
        $response = $controller->__invoke('conv-1');

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertCount(2, $data);
        $this->assertSame('att-1', $data[0]['attachment_id']);
        $this->assertSame('malware.pdf', $data[0]['filename']);
        $this->assertNull($data[0]['deleted_at']);
        $this->assertNotNull($data[1]['deleted_at']);
    }

    // --- DeleteAttachmentController ---

    public function test_delete_returns_404_when_not_found(): void
    {
        $this->handler->method('deleteAttachment')->willReturn(false);
        $controller = new DeleteAttachmentController($this->handler);

        $response = $controller->__invoke('nonexistent-id');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_delete_returns_200_on_success(): void
    {
        $this->handler->method('deleteAttachment')->willReturn(true);
        $controller = new DeleteAttachmentController($this->handler);

        $response = $controller->__invoke('att-1');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Attachment deleted', $data['message']);
    }
}
