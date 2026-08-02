<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\Message;
use PHPUnit\Framework\TestCase;

class MessageSendStatusTest extends TestCase
{
    private function createMockMessage(array $headers = []): Message
    {
        $conversation = $this->createMock(\App\Domain\Communication\Conversation::class);
        $channel = $this->createMock(\App\Domain\Communication\Channel::class);
        $direction = $this->createMock(\App\Domain\Communication\Direction::class);

        return new Message(
            'test-msg-id',
            $conversation,
            $channel,
            $direction,
            'fr',
            'Test Subject',
            'Test body',
            '<p>Test body</p>',
            $headers,
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );
    }

    public function testGetSendStatusReturnsNullWhenNotSet(): void
    {
        $message = $this->createMockMessage();

        $this->assertNull($message->getSendStatus());
    }

    public function testGetSendStatusReturnsValueWhenSet(): void
    {
        $message = $this->createMockMessage(['send_status' => 'draft']);

        $this->assertSame('draft', $message->getSendStatus());
    }

    public function testSetSendStatusStoresValueInHeaders(): void
    {
        $message = $this->createMockMessage();

        $message->setSendStatus('sent');

        $this->assertSame('sent', $message->getSendStatus());
        $this->assertArrayHasKey('send_status', $message->getHeaders());
        $this->assertSame('sent', $message->getHeaders()['send_status']);
    }

    public function testSetSendStatusOverwritesExistingValue(): void
    {
        $message = $this->createMockMessage(['send_status' => 'draft']);

        $message->setSendStatus('sent');

        $this->assertSame('sent', $message->getSendStatus());
    }

    public function testSetSendStatusSupportsAllValidValues(): void
    {
        $message = $this->createMockMessage();

        $validStatuses = ['draft', 'sent', 'failed'];

        foreach ($validStatuses as $status) {
            $message->setSendStatus($status);
            $this->assertSame($status, $message->getSendStatus());
        }
    }

    public function testGetProviderMsgIdReturnsNullWhenNotSet(): void
    {
        $message = $this->createMockMessage();

        $this->assertNull($message->getProviderMsgId());
    }

    public function testGetProviderMsgIdReturnsValueWhenSet(): void
    {
        $message = $this->createMockMessage(['provider_msg_id' => 'gmail-12345']);

        $this->assertSame('gmail-12345', $message->getProviderMsgId());
    }

    public function testSetProviderMsgIdStoresValueInHeaders(): void
    {
        $message = $this->createMockMessage();

        $message->setProviderMsgId('gmail-67890');

        $this->assertSame('gmail-67890', $message->getProviderMsgId());
        $this->assertArrayHasKey('provider_msg_id', $message->getHeaders());
        $this->assertSame('gmail-67890', $message->getHeaders()['provider_msg_id']);
    }

    public function testGetTsSentReturnsNullWhenNotSet(): void
    {
        $message = $this->createMockMessage();

        $this->assertNull($message->getTsSent());
    }

    public function testGetTsSentReturnsDateTimeWhenSet(): void
    {
        $timestamp = '2025-10-12T15:30:00+00:00';
        $message = $this->createMockMessage(['ts_sent' => $timestamp]);

        $tsSent = $message->getTsSent();

        $this->assertInstanceOf(\DateTimeImmutable::class, $tsSent);
        $this->assertSame($timestamp, $tsSent->format(DATE_ATOM));
    }

    public function testSetTsSentStoresFormattedDateInHeaders(): void
    {
        $message = $this->createMockMessage();
        $now = new \DateTimeImmutable('2025-10-12 15:30:00', new \DateTimeZone('UTC'));

        $message->setTsSent($now);

        $tsSent = $message->getTsSent();
        $this->assertInstanceOf(\DateTimeImmutable::class, $tsSent);
        $this->assertSame($now->format(DATE_ATOM), $tsSent->format(DATE_ATOM));
        $this->assertArrayHasKey('ts_sent', $message->getHeaders());
    }

    public function testSetTsSentOverwritesExistingValue(): void
    {
        $oldDate = new \DateTimeImmutable('2025-10-11 10:00:00', new \DateTimeZone('UTC'));
        $newDate = new \DateTimeImmutable('2025-10-12 15:30:00', new \DateTimeZone('UTC'));

        $message = $this->createMockMessage(['ts_sent' => $oldDate->format(DATE_ATOM)]);
        $message->setTsSent($newDate);

        $tsSent = $message->getTsSent();
        $this->assertSame($newDate->format(DATE_ATOM), $tsSent->format(DATE_ATOM));
    }

    public function testMultipleHeaderFieldsCoexist(): void
    {
        $message = $this->createMockMessage([
            'from' => 'test@example.com',
            'to' => 'recipient@example.com',
        ]);

        $message->setSendStatus('draft');
        $message->setProviderMsgId('gmail-12345');
        $message->setTsSent(new \DateTimeImmutable());

        $headers = $message->getHeaders();

        $this->assertArrayHasKey('from', $headers);
        $this->assertArrayHasKey('to', $headers);
        $this->assertArrayHasKey('send_status', $headers);
        $this->assertArrayHasKey('provider_msg_id', $headers);
        $this->assertArrayHasKey('ts_sent', $headers);

        $this->assertSame('test@example.com', $headers['from']);
        $this->assertSame('recipient@example.com', $headers['to']);
        $this->assertSame('draft', $headers['send_status']);
        $this->assertSame('gmail-12345', $headers['provider_msg_id']);
    }

    public function testSendStatusWorkflowDraftToSent(): void
    {
        $message = $this->createMockMessage();

        // Step 1: Create as draft
        $message->setSendStatus('draft');
        $this->assertSame('draft', $message->getSendStatus());
        $this->assertNull($message->getProviderMsgId());
        $this->assertNull($message->getTsSent());

        // Step 2: Mark as sent
        $message->setSendStatus('sent');
        $message->setProviderMsgId('gmail-99999');
        $message->setTsSent(new \DateTimeImmutable());

        $this->assertSame('sent', $message->getSendStatus());
        $this->assertSame('gmail-99999', $message->getProviderMsgId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $message->getTsSent());
    }

    public function testHeadersAreJsonSerializable(): void
    {
        $message = $this->createMockMessage();
        $message->setSendStatus('sent');
        $message->setProviderMsgId('gmail-12345');
        $message->setTsSent(new \DateTimeImmutable('2025-10-12 15:30:00', new \DateTimeZone('UTC')));

        $headers = $message->getHeaders();
        $json = json_encode($headers);

        $this->assertIsString($json);
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertSame('sent', $decoded['send_status']);
        $this->assertSame('gmail-12345', $decoded['provider_msg_id']);
        $this->assertArrayHasKey('ts_sent', $decoded);
    }
}
