<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

/**
 * Spec 061 — Sprint 1 — Task 1.1
 *
 * Domain helper Message::canExtractIocs() must return true only when the
 * message direction is 'in' (incoming, scammer-controlled). Outgoing messages
 * are produced by ScamBuster and must never feed the IOC extraction pipeline.
 */
final class MessageCanExtractIocsTest extends TestCase
{
    public function testReturnsTrueForIncomingMessage(): void
    {
        $message = $this->buildMessageWithDirection('in');
        $this->assertTrue($message->canExtractIocs());
    }

    public function testReturnsFalseForOutgoingMessage(): void
    {
        $message = $this->buildMessageWithDirection('out');
        $this->assertFalse($message->canExtractIocs());
    }

    private function buildMessageWithDirection(string $directionCode): Message
    {
        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn($directionCode);

        return new Message(
            'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a',
            $this->createMock(Conversation::class),
            $this->createMock(Channel::class),
            $direction,
            'fr',
            'Test',
            'body',
            null,
            ['from' => 'x@example.com'],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('now'),
        );
    }
}
