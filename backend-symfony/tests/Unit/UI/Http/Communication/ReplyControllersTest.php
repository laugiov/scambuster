<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use App\UI\Http\Communication\ComposeReplyController;
use App\UI\Http\Communication\GetReplyController;
use App\UI\Http\Communication\MarkReplySentController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ReplyControllersTest extends TestCase
{
    private ReplyHandler&MockObject $handler;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ReplyHandler::class);
    }

    // --- GetReplyController ---

    public function test_get_reply_returns_404_when_not_found(): void
    {
        $this->handler->method('getMessage')->willReturn(null);
        $controller = new GetReplyController($this->handler);

        $response = $controller->__invoke('nonexistent');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_get_reply_returns_404_when_deleted(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getDeletedAt')->willReturn(new \DateTimeImmutable());

        $this->handler->method('getMessage')->willReturn($message);
        $controller = new GetReplyController($this->handler);

        $response = $controller->__invoke('deleted-msg');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_get_reply_returns_200_with_details(): void
    {
        $parent = $this->createMock(Message::class);
        $parent->method('getProviderMsgId')->willReturn('gmail-msg-id');

        $message = $this->createMock(Message::class);
        $message->method('getDeletedAt')->willReturn(null);
        $message->method('getMsgId')->willReturn('msg-1');
        $message->method('getSendStatus')->willReturn('draft');
        $message->method('getHeaders')->willReturn(['to' => 'scammer@test.com']);
        $message->method('getSubject')->willReturn('Re: Test');
        $message->method('getBodyText')->willReturn('Reply text');
        $message->method('getBodyHtml')->willReturn(null);
        $message->method('getReplyTo')->willReturn($parent);

        $this->handler->method('getMessage')->willReturn($message);
        $controller = new GetReplyController($this->handler);

        $response = $controller->__invoke('msg-1');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('msg-1', $data['msg_id']);
        $this->assertSame('draft', $data['send_status']);
        $this->assertSame('gmail-msg-id', $data['meta']['parent_gmail_msg_id']);
    }

    public function test_get_reply_handles_no_parent(): void
    {
        $message = $this->createMock(Message::class);
        $message->method('getDeletedAt')->willReturn(null);
        $message->method('getMsgId')->willReturn('msg-1');
        $message->method('getSendStatus')->willReturn('draft');
        $message->method('getHeaders')->willReturn([]);
        $message->method('getSubject')->willReturn(null);
        $message->method('getBodyText')->willReturn('Text');
        $message->method('getBodyHtml')->willReturn(null);
        $message->method('getReplyTo')->willReturn(null);

        $this->handler->method('getMessage')->willReturn($message);
        $controller = new GetReplyController($this->handler);

        $response = $controller->__invoke('msg-1');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertNull($data['meta']['parent_gmail_msg_id']);
    }

    // --- ComposeReplyController ---

    public function test_compose_returns_404_when_not_found(): void
    {
        $this->handler->method('composeHeaders')->willReturn(null);
        $controller = new ComposeReplyController($this->handler);

        $response = $controller->__invoke('nonexistent');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_compose_returns_200_with_headers(): void
    {
        $this->handler->method('composeHeaders')->willReturn([
            'msg_id' => 'msg-1',
            'to' => 'scammer@test.com',
            'from' => 'honeypot@test.com',
            'subject' => 'Re: Test',
            'in_reply_to' => 'parent-id@test.com',
            'references' => 'ref1@test.com',
            'thread_id' => 'thread-1',
            'safe_to_send' => true,
            'rate_limited' => false,
            'checks' => ['safelist_ok' => true, 'kill_switch_off' => true],
        ]);

        $controller = new ComposeReplyController($this->handler);
        $response = $controller->__invoke('msg-1');

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('msg-1', $data['msg_id']);
        $this->assertTrue($data['safe_to_send']);
    }

    public function test_compose_returns_400_on_runtime_exception(): void
    {
        $this->handler->method('composeHeaders')
            ->willThrowException(new \RuntimeException('Message is not a reply'));

        $controller = new ComposeReplyController($this->handler);
        $response = $controller->__invoke('msg-1');

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Message is not a reply', $data['error']);
    }

    // --- MarkReplySentController ---

    public function test_mark_sent_returns_400_on_invalid_json(): void
    {
        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/api/v1/communication/reply/msg-1/sent', 'POST', [], [], [], [], 'not-json');

        $response = $controller->__invoke('msg-1', $request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_mark_sent_returns_400_on_missing_fields(): void
    {
        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/sent', 'POST', [], [], [], [], '{"provider":"gmail"}');

        $response = $controller->__invoke('msg-1', $request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Missing required fields', $data['error']);
    }

    public function test_mark_sent_returns_204_on_success(): void
    {
        $this->handler->method('markAsSent')->willReturn(true);

        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/sent', 'POST', [], [], [], [], json_encode([
            'provider' => 'gmail',
            'provider_msg_id' => 'gmail-msg-id',
            'ts_sent' => '2026-01-01T00:00:00+00:00',
        ]));

        $response = $controller->__invoke('msg-1', $request);
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_mark_sent_returns_404_when_message_not_found(): void
    {
        $this->handler->method('markAsSent')->willReturn(false);

        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/sent', 'POST', [], [], [], [], json_encode([
            'provider' => 'gmail',
            'provider_msg_id' => 'gmail-id',
            'ts_sent' => '2026-01-01T00:00:00+00:00',
        ]));

        $response = $controller->__invoke('msg-1', $request);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_mark_sent_returns_400_on_already_sent(): void
    {
        $this->handler->method('markAsSent')
            ->willThrowException(new \RuntimeException('Message already sent'));

        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/sent', 'POST', [], [], [], [], json_encode([
            'provider' => 'gmail',
            'provider_msg_id' => 'gmail-id',
            'ts_sent' => '2026-01-01T00:00:00+00:00',
        ]));

        $response = $controller->__invoke('msg-1', $request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_mark_sent_passes_optional_parameters(): void
    {
        $this->handler->expects($this->once())
            ->method('markAsSent')
            ->with(
                'msg-1',
                'gmail',
                'gmail-id',
                $this->isInstanceOf(\DateTimeImmutable::class),
                ['thread_id' => 't-1', 'message-id' => 'mid@gmail.com'],
                'conv-1',
            )
            ->willReturn(true);

        $controller = new MarkReplySentController($this->handler);
        $request = Request::create('/sent', 'POST', [], [], [], [], json_encode([
            'provider' => 'gmail',
            'provider_msg_id' => 'gmail-id',
            'ts_sent' => '2026-01-01T00:00:00+00:00',
            'sent_headers' => ['thread_id' => 't-1', 'message-id' => 'mid@gmail.com'],
            'conv_id' => 'conv-1',
        ]));

        $controller->__invoke('msg-1', $request);
    }
}
