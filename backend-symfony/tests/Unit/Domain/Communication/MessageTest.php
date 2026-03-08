<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_it_creates_message_with_valid_data(): void
    {
        $conversation = $this->createMock(\App\Domain\Communication\Conversation::class);
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $direction = $this->createMock(\App\Domain\Communication\Direction::class);
        $replyTo = $this->createMock(\App\Domain\Communication\Message::class);
        $msgId = 'b3b6c1e2-8e2a-4e2a-9e2a-8e2a4e2a9e2a';
        $langDetect = 'fr';
        $subject = 'Test subject';
        $bodyText = 'Ceci est le corps du message.';
        $bodyHtml = '<p>Ceci est le corps du message.</p>';
        $headers = ['From' => 'test@example.com'];
        $compositeHash = bin2hex(random_bytes(32));
        $vectorId = 'a1a2a3a4-a5a6-a7a8-a9a0-a1a2a3a4a5a6';
        $tsMsg = new \DateTimeImmutable('-1 hour');
        $tsIngest = new \DateTimeImmutable('now');
        $deletedAt = null;

        $message = new Message(
            $msgId,
            $conversation,
            $channel,
            $direction,
            $langDetect,
            $subject,
            $bodyText,
            $bodyHtml,
            $headers,
            $compositeHash,
            $vectorId,
            $replyTo,
            $tsMsg,
            $tsIngest,
            $deletedAt
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame($msgId, $message->getMsgId());
        $this->assertSame($conversation, $message->getConversation());
        $this->assertSame($channel, $message->getChannel());
        $this->assertSame($direction, $message->getDirection());
        $this->assertSame($langDetect, $message->getLangDetect());
        $this->assertSame($subject, $message->getSubject());
        $this->assertSame($bodyText, $message->getBodyText());
        $this->assertSame($bodyHtml, $message->getBodyHtml());
        $this->assertSame($headers, $message->getHeaders());
        $this->assertSame($compositeHash, $message->getCompositeHash());
        $this->assertSame($vectorId, $message->getVectorId());
        $this->assertSame($replyTo, $message->getReplyTo());
        $this->assertSame($tsMsg, $message->getTsMsg());
        $this->assertSame($tsIngest, $message->getTsIngest());
        $this->assertSame($deletedAt, $message->getDeletedAt());
    }
} 