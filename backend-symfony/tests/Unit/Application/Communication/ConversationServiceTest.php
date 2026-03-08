<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Communication;

use App\Application\Communication\ConversationService;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Channel;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\ConversationChannel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ConversationServiceTest extends TestCase
{
    public function testCreateConversation(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new ConversationService($em);

        $channel = $this->createMock(Channel::class);
        $scamType = $this->createMock(ScamType::class);
        $account = $this->createMock(MailAccount::class);
        $status = \App\Domain\Communication\ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-123';

        $conv = $service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->assertInstanceOf(Conversation::class, $conv);
    }

    public function testAddChannelToConversation(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new ConversationService($em);
        $conv = $this->createMock(Conversation::class);
        $channel = $this->createMock(Channel::class);
        $tsFirstChannel = new \DateTimeImmutable();

        $service->addChannelToConversation($conv, $channel, $tsFirstChannel);
        $this->assertTrue(true);
    }

    public function testChangeConversationStatus(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $service = new \App\Application\Communication\ConversationService($em);
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $scamType = $this->createMock(\App\Domain\Communication\ScamType::class);
        $account = $this->createMock(\App\Domain\Communication\MailAccount::class);
        $status = \App\Domain\Communication\ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-123';
        $conv = new \App\Domain\Communication\Conversation('00000000-0000-0000-0000-000000000001', $channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $em->method('find')->willReturn($conv);
        $service->changeConversationStatus($conv, \App\Domain\Communication\ConversationStatus::CLOSED);
        $this->assertSame(\App\Domain\Communication\ConversationStatus::CLOSED, $conv->getStatus());
    }

    public function testChangeConversationStatusThrowsIfNotFound(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('find')->willReturn(null);
        $service = new \App\Application\Communication\ConversationService($em);
        $conv = $this->createMock(\App\Domain\Communication\Conversation::class);
        $status = \App\Domain\Communication\ConversationStatus::CLOSED;
        $this->expectException(\App\Application\Communication\ConversationNotFoundException::class);
        $service->changeConversationStatus($conv, $status);
    }
} 