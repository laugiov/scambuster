<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\ConversationHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use App\UI\Http\Communication\CreateConversationController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class CreateConversationControllerTest extends TestCase
{
    private ConversationHandler&MockObject $handler;
    private CreateConversationController $controller;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ConversationHandler::class);
        $this->controller = new CreateConversationController($this->handler);
    }

    private function validPayload(): array
    {
        return [
            'primary_channel_id' => 1,
            'scam_type_id' => 2,
            'account_id' => 'acc-uuid',
            'status' => 'open',
            'score_risk' => 30,
            'ts_first' => '2026-01-01T00:00:00+00:00',
            'ts_last' => '2026-01-01T01:00:00+00:00',
            'stix_id' => 'shadow-test-stix',
        ];
    }

    public function test_returns_400_on_invalid_json(): void
    {
        $request = Request::create('/api/v1/communication/conversation', 'POST', [], [], [], [], 'not-json');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_returns_400_on_missing_fields(): void
    {
        $request = Request::create('/api/v1/communication/conversation', 'POST', [], [], [], [], '{"primary_channel_id":1}');
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Missing field', $data['error']);
    }

    public function test_returns_400_on_invalid_reference(): void
    {
        $this->handler->method('getChannel')->willReturn(null);
        $this->handler->method('getScamType')->willReturn(null);
        $this->handler->method('getMailAccount')->willReturn(null);

        $request = Request::create('/api/v1/communication/conversation', 'POST', [], [], [], [], json_encode($this->validPayload()));
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Invalid reference', $data['error']);
    }

    public function test_returns_201_on_success(): void
    {
        $channel = $this->createMock(Channel::class);
        $scamType = $this->createMock(ScamType::class);
        $account = $this->createMock(MailAccount::class);

        $this->handler->method('getChannel')->willReturn($channel);
        $this->handler->method('getScamType')->willReturn($scamType);
        $this->handler->method('getMailAccount')->willReturn($account);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('new-conv-uuid');
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);
        $this->handler->method('createConversation')->willReturn($conversation);

        $request = Request::create('/api/v1/communication/conversation', 'POST', [], [], [], [], json_encode($this->validPayload()));
        $response = $this->controller->__invoke($request);
        $this->assertSame(201, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('new-conv-uuid', $data['conv_id']);
    }

    public function test_returns_400_on_invalid_status(): void
    {
        $payload = $this->validPayload();
        $payload['status'] = 'nonexistent_status';

        $channel = $this->createMock(Channel::class);
        $scamType = $this->createMock(ScamType::class);
        $account = $this->createMock(MailAccount::class);

        $this->handler->method('getChannel')->willReturn($channel);
        $this->handler->method('getScamType')->willReturn($scamType);
        $this->handler->method('getMailAccount')->willReturn($account);

        $request = Request::create('/api/v1/communication/conversation', 'POST', [], [], [], [], json_encode($payload));
        $response = $this->controller->__invoke($request);
        $this->assertSame(400, $response->getStatusCode());
    }
}
