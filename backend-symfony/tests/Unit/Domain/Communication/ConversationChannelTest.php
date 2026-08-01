<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\ConversationChannel;
use PHPUnit\Framework\TestCase;

class ConversationChannelTest extends TestCase
{
    public function test_it_creates_conversation_channel_with_valid_data(): void
    {
        $conversation = $this->createMock(\App\Domain\Communication\Conversation::class);
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $tsFirstChannel = new \DateTimeImmutable('now');

        $cc = new ConversationChannel($conversation, $channel, $tsFirstChannel);

        $this->assertInstanceOf(ConversationChannel::class, $cc);
        $this->assertSame($conversation, $cc->getConversation());
        $this->assertSame($channel, $cc->getChannel());
        $this->assertSame($tsFirstChannel, $cc->getTsFirstChannel());
    }
} 