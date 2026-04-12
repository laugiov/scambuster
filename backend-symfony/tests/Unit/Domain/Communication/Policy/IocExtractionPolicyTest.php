<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication\Policy;

use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\Domain\Communication\Policy\IocExtractionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Spec 065h — Phase 1a — IocExtractionPolicy unit tests.
 *
 * Extracted from Message::canExtractIocs() per the god classes
 * decomposition spec. The policy is a stateless service that
 * determines whether IOC extraction is allowed for a given message.
 */
final class IocExtractionPolicyTest extends TestCase
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
        $policy = new IocExtractionPolicy();
        $message = $this->makeMessage('in');

        $this->assertTrue($policy->allows($message));
    }

    public function test_rejects_outgoing_messages(): void
    {
        $policy = new IocExtractionPolicy();
        $message = $this->makeMessage('out');

        $this->assertFalse($policy->allows($message));
    }

    public function test_rejects_unknown_direction(): void
    {
        $policy = new IocExtractionPolicy();
        $message = $this->makeMessage('unknown');

        $this->assertFalse($policy->allows($message));
    }

    public function test_is_stateless(): void
    {
        $policy = new IocExtractionPolicy();
        $msg1 = $this->makeMessage('in');
        $msg2 = $this->makeMessage('out');

        // Multiple calls with different messages should not affect each other
        $this->assertTrue($policy->allows($msg1));
        $this->assertFalse($policy->allows($msg2));
        $this->assertTrue($policy->allows($msg1)); // still true
    }
}
