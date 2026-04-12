<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Spec 061 — Sprint 1 — Task 1.1 (updated by Spec 065h)
 *
 * IocExtractionPolicy::allows() must return true only when the
 * message direction is 'in' (incoming, scammer-controlled). Outgoing
 * messages are produced by ScamBuster and must never feed the IOC
 * extraction pipeline.
 *
 * Previously tested via Message::canExtractIocs() which was extracted
 * to IocExtractionPolicy in Spec 065h (god classes decomposition).
 */
final class MessageCanExtractIocsTest extends TestCase
{
    private IocExtractionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new IocExtractionPolicy();
    }

    public function testReturnsTrueForIncomingMessage(): void
    {
        $message = $this->buildMessageWithDirection('in');
        $this->assertTrue($this->policy->allows($message));
    }

    public function testReturnsFalseForOutgoingMessage(): void
    {
        $message = $this->buildMessageWithDirection('out');
        $this->assertFalse($this->policy->allows($message));
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
