<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use PHPUnit\Framework\TestCase;

class ConversationTest extends TestCase
{
    public function test_it_creates_conversation_with_valid_data(): void
    {
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $scamType = $this->createMock(\App\Domain\Communication\ScamType::class);
        $account = $this->createMock(\App\Domain\Communication\MailAccount::class);
        $convId = 'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a';
        $status = ConversationStatus::OPEN;
        $scoreRisk = 85;
        $tsFirst = new \DateTimeImmutable('-1 day');
        $tsLast = new \DateTimeImmutable('now');
        $stixId = 'stix--1234abcd';

        $conversation = new Conversation(
            $convId,
            $channel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            $tsFirst,
            $tsLast,
            $stixId
        );

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertSame($convId, $conversation->getConvId());
        $this->assertSame($channel, $conversation->getPrimaryChannel());
        $this->assertSame($scamType, $conversation->getScamType());
        $this->assertSame($account, $conversation->getAccount());
        $this->assertSame($status, $conversation->getStatus());
        $this->assertSame($scoreRisk, $conversation->getScoreRisk());
        $this->assertSame($tsFirst, $conversation->getTsFirst());
        $this->assertSame($tsLast, $conversation->getTsLast());
        $this->assertSame($stixId, $conversation->getStixId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conversation->getUpdatedAt());
        // New Sprint 3 fields with default values
        $this->assertSame('DELIVERY_UNKNOWN', $conversation->getDelivery());
        $this->assertSame('TLP_AMBER', $conversation->getTlp());
    }

    public function test_it_sets_and_gets_delivery_status(): void
    {
        $conversation = $this->createTestConversation();

        $conversation->setDelivery('SENT');
        $this->assertSame('SENT', $conversation->getDelivery());

        $conversation->setDelivery('DELIVERED');
        $this->assertSame('DELIVERED', $conversation->getDelivery());

        $conversation->setDelivery('READ');
        $this->assertSame('READ', $conversation->getDelivery());

        $conversation->setDelivery('REPLIED');
        $this->assertSame('REPLIED', $conversation->getDelivery());

        $conversation->setDelivery('BOUNCED');
        $this->assertSame('BOUNCED', $conversation->getDelivery());

        $conversation->setDelivery('DELIVERY_UNKNOWN');
        $this->assertSame('DELIVERY_UNKNOWN', $conversation->getDelivery());
    }

    public function test_it_throws_exception_for_invalid_delivery_status(): void
    {
        $conversation = $this->createTestConversation();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid delivery status: INVALID_STATUS');

        $conversation->setDelivery('INVALID_STATUS');
    }

    public function test_it_sets_and_gets_tlp_level(): void
    {
        $conversation = $this->createTestConversation();

        $conversation->setTlp('TLP_WHITE');
        $this->assertSame('TLP_WHITE', $conversation->getTlp());

        $conversation->setTlp('TLP_GREEN');
        $this->assertSame('TLP_GREEN', $conversation->getTlp());

        $conversation->setTlp('TLP_AMBER');
        $this->assertSame('TLP_AMBER', $conversation->getTlp());

        $conversation->setTlp('TLP_RED');
        $this->assertSame('TLP_RED', $conversation->getTlp());
    }

    public function test_it_throws_exception_for_invalid_tlp_level(): void
    {
        $conversation = $this->createTestConversation();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid TLP level: TLP_INVALID');

        $conversation->setTlp('TLP_INVALID');
    }

    private function createTestConversation(): Conversation
    {
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $scamType = $this->createMock(\App\Domain\Communication\ScamType::class);
        $account = $this->createMock(\App\Domain\Communication\MailAccount::class);

        return new Conversation(
            'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a',
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            85,
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('now'),
            'stix--1234abcd'
        );
    }
} 