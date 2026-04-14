<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\AttachmentHandler;
use App\Domain\Communication\Attachment;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AttachmentHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->handler = new AttachmentHandler($this->em);
    }

    public function testDeleteAttachmentReturnsFalseWhenNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $this->em->method('getRepository')
            ->with(Attachment::class)
            ->willReturn($repo);

        $this->assertFalse($this->handler->deleteAttachment('non-existent-id'));
    }

    public function testDeleteAttachmentReturnsTrueOnSuccess(): void
    {
        $attachment = $this->createMock(Attachment::class);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($attachment);

        $this->em->method('getRepository')
            ->with(Attachment::class)
            ->willReturn($repo);

        $this->em->expects($this->once())->method('remove')->with($attachment);
        $this->em->expects($this->once())->method('flush');

        $this->assertTrue($this->handler->deleteAttachment('some-id'));
    }

    public function testListConversationAttachmentsReturnsEmptyWhenConversationNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($repo);

        $this->assertSame([], $this->handler->listConversationAttachments('non-existent'));
    }

    public function testListConversationAttachmentsReturnsEmptyWhenNoMessages(): void
    {
        $conversation = $this->createMock(Conversation::class);

        $convRepo = $this->createMock(EntityRepository::class);
        $convRepo->method('find')->willReturn($conversation);

        $msgRepo = $this->createMock(EntityRepository::class);
        $msgRepo->method('findBy')->willReturn([]);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($convRepo, $msgRepo) {
                if ($class === Conversation::class) {
                    return $convRepo;
                }

                if ($class === 'App\\Domain\\Communication\\Message') {
                    return $msgRepo;
                }

                return $this->createMock(EntityRepository::class);
            });

        $this->assertSame([], $this->handler->listConversationAttachments('conv-id'));
    }

    public function testListConversationAttachmentsReturnsAttachments(): void
    {
        $conversation = $this->createMock(Conversation::class);
        $message = $this->createMock(Message::class);
        $attachment = $this->createMock(Attachment::class);

        $convRepo = $this->createMock(EntityRepository::class);
        $convRepo->method('find')->willReturn($conversation);

        $msgRepo = $this->createMock(EntityRepository::class);
        $msgRepo->method('findBy')->willReturn([$message]);

        $attRepo = $this->createMock(EntityRepository::class);
        $attRepo->method('findBy')->willReturn([$attachment]);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($convRepo, $msgRepo, $attRepo) {
                if ($class === Conversation::class) {
                    return $convRepo;
                }

                if ($class === 'App\\Domain\\Communication\\Message') {
                    return $msgRepo;
                }

                if ($class === Attachment::class) {
                    return $attRepo;
                }

                return $this->createMock(EntityRepository::class);
            });

        $result = $this->handler->listConversationAttachments('conv-id');
        $this->assertCount(1, $result);
        $this->assertSame($attachment, $result[0]);
    }

    public function testGetAttachmentReturnsNullWhenNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $this->em->method('getRepository')
            ->with(Attachment::class)
            ->willReturn($repo);

        $this->assertNull($this->handler->getAttachment('non-existent'));
    }

    public function testGetAttachmentReturnsAttachment(): void
    {
        $attachment = $this->createMock(Attachment::class);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($attachment);

        $this->em->method('getRepository')
            ->with(Attachment::class)
            ->willReturn($repo);

        $this->assertSame($attachment, $this->handler->getAttachment('some-id'));
    }

    public function testGetConversationReturnsNullWhenNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($repo);

        $this->assertNull($this->handler->getConversation('non-existent'));
    }

    public function testGetConversationReturnsConversation(): void
    {
        $conversation = $this->createMock(Conversation::class);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conversation);

        $this->em->method('getRepository')
            ->with(Conversation::class)
            ->willReturn($repo);

        $this->assertSame($conversation, $this->handler->getConversation('some-id'));
    }
}
