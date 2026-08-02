<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI;

use App\Application\Communication\ScamClassificationHandler;
use App\UI\Http\Communication\AutoClassifyConversationController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class AutoClassifyConversationControllerTest extends TestCase
{
    private ScamClassificationHandler&MockObject $handler;
    private AutoClassifyConversationController $controller;

    protected function setUp(): void
    {
        $this->handler = $this->createMock(ScamClassificationHandler::class);
        $this->controller = new AutoClassifyConversationController($this->handler);
    }

    public function testSuccessfulClassification(): void
    {
        $this->handler->method('autoClassifyConversation')
            ->willReturn([
                'scam_type_code' => 'PHISHING',
                'scam_type_label' => 'Phishing',
                'persona_code' => 'generic_user',
                'persona_label' => 'Generic User',
                'confidence' => 0.95,
                'is_new_scam_type' => false,
                'is_new_persona' => false,
            ]);

        $request = new Request([], [], [], [], [], [], '{"force": true}');
        $response = $this->controller->__invoke('conv-1', $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('PHISHING', $data['scam_type_code']);
        $this->assertSame('conv-1', $data['conv_id']);
        $this->assertSame(0.95, $data['confidence']);
    }

    public function testNotFoundError(): void
    {
        $this->handler->method('autoClassifyConversation')
            ->willThrowException(new \RuntimeException('Conversation not found'));

        $request = new Request([], [], [], [], [], [], '{}');
        $response = $this->controller->__invoke('conv-missing', $request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testGenericRuntimeError(): void
    {
        $this->handler->method('autoClassifyConversation')
            ->willThrowException(new \RuntimeException('Classification failed'));

        $request = new Request([], [], [], [], [], [], '{}');
        $response = $this->controller->__invoke('conv-1', $request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testEmptyRequestBody(): void
    {
        $this->handler->method('autoClassifyConversation')
            ->willReturn([
                'scam_type_code' => 'UNKNOWN',
                'scam_type_label' => 'Unknown',
                'persona_code' => null,
                'persona_label' => null,
                'confidence' => 0.5,
                'is_new_scam_type' => false,
                'is_new_persona' => false,
            ]);

        $request = new Request([], [], [], [], [], [], '');
        $response = $this->controller->__invoke('conv-1', $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWithForceAndCustomThreshold(): void
    {
        $this->handler->expects($this->once())
            ->method('autoClassifyConversation')
            ->with('conv-1', true, 0.9)
            ->willReturn([
                'scam_type_code' => 'ROMANCE',
                'scam_type_label' => 'Romance Scam',
                'persona_code' => 'lonely_person',
                'persona_label' => 'Lonely Person',
                'confidence' => 0.92,
                'is_new_scam_type' => false,
                'is_new_persona' => false,
            ]);

        $request = new Request([], [], [], [], [], [], '{"force": true, "confidence_threshold": 0.9}');
        $response = $this->controller->__invoke('conv-1', $request);

        $this->assertSame(200, $response->getStatusCode());
    }
}
