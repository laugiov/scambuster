<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\Policy;

use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\Policy\TtpExtractionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * TtpExtractionPolicy unit tests.
 *
 * TTPs describe the scammer's behaviour; outgoing messages are our own
 * generated replies and must never be tagged. This suite is the regression
 * lock for the inbound-only rule.
 */
final class TtpExtractionPolicyTest extends TestCase
{
    private function makeMessage(string $directionCode): Message
    {
        $direction = $this->createMock(Direction::class);
        $direction->method('getCode')->willReturn($directionCode);

        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $conversation = $this->createMock(\App\Domain\Communication\Conversation::class);

        return new Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conversation,
            $channel,
            $direction,
            'fr',
            'Test subject',
            'Test body',
            null,
            [],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
        );
    }

    public function test_allows_incoming_messages(): void
    {
        $policy = new TtpExtractionPolicy();
        $message = $this->makeMessage('in');

        $this->assertTrue($policy->allows($message));
    }

    public function test_outgoing_messages_are_never_tagged_with_ttps(): void
    {
        // Regression lock: outgoing messages are ScamBuster-generated replies.
        // Tagging them would attribute our own persona behaviour to the scammer.
        $policy = new TtpExtractionPolicy();
        $message = $this->makeMessage('out');

        $this->assertFalse($policy->allows($message));
    }

    public function test_rejects_unknown_direction(): void
    {
        $policy = new TtpExtractionPolicy();
        $message = $this->makeMessage('unknown');

        $this->assertFalse($policy->allows($message));
    }
}
