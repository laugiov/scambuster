<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\ReplyHandler;
use App\Domain\LLM\Exception\LlmBudgetExceededException;
use App\UI\Http\Communication\GenerateReplyController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class GenerateReplyControllerTest extends TestCase
{
    private ReplyHandler&MockObject $handler;
    private GenerateReplyController $controller;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ReplyHandler::class);
        $this->controller = new GenerateReplyController($this->handler);
    }

    public function test_returns_400_on_invalid_json(): void
    {
        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], 'not-json');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid JSON', $data['error']);
    }

    public function test_returns_400_when_missing_conv_id(): void
    {
        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"last_msg_id":"msg-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Missing required fields', $data['error']);
    }

    public function test_returns_400_when_missing_last_msg_id(): void
    {
        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_when_handler_returns_null(): void
    {
        $this->handler->method('generateReply')->willReturn(null);

        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1","last_msg_id":"msg-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Could not generate reply', $data['error']);
    }

    public function test_returns_201_on_success(): void
    {
        $this->handler->method('generateReply')->willReturn([
            'msg_id' => 'new-msg-1',
            'conv_id' => 'conv-1',
            'to' => 'scammer@example.com',
            'subject' => 'Re: Test',
            'draft' => ['text' => 'Hello there, thank you for your message.'],
            'meta' => ['model' => 'gpt-4o', 'attempts' => 1],
        ]);

        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1","last_msg_id":"msg-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_returns_503_on_budget_exceeded(): void
    {
        $resetAt = new \DateTimeImmutable('+1 hour');
        $this->handler->method('generateReply')
            ->willThrowException(new LlmBudgetExceededException(5.5, 5.0, $resetAt));

        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1","last_msg_id":"msg-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(503, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('LLM monthly budget exceeded', $data['error']);
        $this->assertSame('BUDGET_EXCEEDED', $data['code']);
        $this->assertEqualsWithDelta(5.5, $data['current_usd'], 0.01);
        $this->assertEqualsWithDelta(5.0, $data['limit_usd'], 0.01);
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    public function test_returns_400_on_runtime_exception(): void
    {
        $this->handler->method('generateReply')
            ->willThrowException(new \RuntimeException('Conversation not found'));

        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1","last_msg_id":"msg-1"}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Conversation not found', $data['error']);
    }

    public function test_passes_force_and_reason_parameters(): void
    {
        $this->handler->expects($this->once())
            ->method('generateReply')
            ->with('conv-1', 'msg-1', true, 'auto_draft_on_inbound')
            ->willReturn(null);

        $request = Request::create('/api/v1/communication/reply/generate', 'POST', [], [], [], [], '{"conv_id":"conv-1","last_msg_id":"msg-1","force":true,"reason":"auto_draft_on_inbound"}');
        $this->controller->__invoke($request);
    }
}
