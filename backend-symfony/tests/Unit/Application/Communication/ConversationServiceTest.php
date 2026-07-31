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
use App\Application\Communication\ConversationNotFoundException;
use Doctrine\ORM\EntityRepository;

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

    // ================================================================== //
    //  Merged from ConversationServiceAdditionalTest
    // ================================================================== //

    private function createConversation(): Conversation
    {
        return new Conversation(
            '00000000-0000-0000-0000-000000000099',
            $this->createMock(Channel::class),
            $this->createMock(ScamType::class),
            $this->createMock(MailAccount::class),
            ConversationStatus::OPEN,
            30,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-unit-test'
        );
    }

    public function testFindConversationByIdReturnsConversation(): void
    {
        $conv = $this->createConversation();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conv);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);
        $found = $service->findConversationById($conv->getConvId());

        $this->assertSame($conv->getConvId(), $found->getConvId());
    }

    public function testFindConversationByIdThrowsForNonExistent(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);

        $this->expectException(ConversationNotFoundException::class);
        $service->findConversationById('ffffffff-ffff-ffff-ffff-ffffffffffff');
    }

    public function testFindConversationByStixIdReturnsConversation(): void
    {
        $conv = $this->createConversation();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->with(['stixId' => 'stix-unit-test'])->willReturn($conv);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);
        $found = $service->findConversationByStixId('stix-unit-test');

        $this->assertSame($conv->getConvId(), $found->getConvId());
    }

    public function testFindConversationByStixIdThrowsForNonExistent(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);

        $this->expectException(ConversationNotFoundException::class);
        $service->findConversationByStixId('stix-nonexistent');
    }

    public function testSoftDeleteConversationSetsDeletedAt(): void
    {
        $conv = $this->createConversation();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conv);
        $em->expects($this->once())->method('flush');

        $service = new ConversationService($em);
        $service->softDeleteConversation($conv);

        $this->assertNotNull($conv->getDeletedAt());
    }

    public function testSoftDeleteConversationWithCustomDate(): void
    {
        $conv = $this->createConversation();
        $deletedAt = new \DateTimeImmutable('-2 hours');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($conv);
        $em->expects($this->once())->method('flush');

        $service = new ConversationService($em);
        $service->softDeleteConversation($conv, $deletedAt);

        $this->assertSame(
            $deletedAt->format('Y-m-d H:i:s'),
            $conv->getDeletedAt()->format('Y-m-d H:i:s')
        );
    }

    public function testSoftDeleteConversationThrowsForNonExistent(): void
    {
        $conv = $this->createConversation();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $service = new ConversationService($em);

        $this->expectException(ConversationNotFoundException::class);
        $service->softDeleteConversation($conv);
    }

    public function testUpdateConversationFieldsUpdatesScoreRisk(): void
    {
        $conv = $this->createConversation();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conv);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects($this->once())->method('flush');

        $service = new ConversationService($em);
        $service->updateConversationFields($conv->getConvId(), ['scoreRisk' => 88]);

        $this->assertSame(88, $conv->getScoreRisk());
    }

    public function testUpdateConversationFieldsIgnoresUnknownFields(): void
    {
        $conv = $this->createConversation();
        $originalRisk = $conv->getScoreRisk();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conv);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);
        $service->updateConversationFields($conv->getConvId(), ['unknownField' => 'value']);

        $this->assertSame($originalRisk, $conv->getScoreRisk());
    }

    public function testUpdateConversationFieldsThrowsForNonExistent(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);

        $this->expectException(ConversationNotFoundException::class);
        $service->updateConversationFields('ffffffff-ffff-ffff-ffff-ffffffffffff', ['scoreRisk' => 99]);
    }

    public function testUpdateConversationFieldsUpdatesStatus(): void
    {
        $conv = $this->createConversation();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($conv);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $service = new ConversationService($em);
        $service->updateConversationFields($conv->getConvId(), ['status' => ConversationStatus::CLOSED]);

        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus());
    }
} 