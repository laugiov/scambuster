<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\IocHandler;
use App\Domain\Communication\ObservedIoc;
use App\UI\Http\Communication\ExportMispController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportMispControllerTest extends TestCase
{
    private IocHandler&MockObject $iocHandler;

    protected function setUp(): void
    {
        $this->iocHandler = $this->createMock(IocHandler::class);
    }

    public function test_returns_404_when_no_iocs(): void
    {
        $this->iocHandler->method('getConversationIocs')->willReturn([]);

        $controller = new ExportMispController($this->iocHandler);
        $response = $controller->__invoke('conv-123');

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('No IOCs found for conversation', $data['error']);
    }

    public function test_builds_misp_event_from_iocs(): void
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn([
            'type' => 'email',
            'value' => 'scammer@evil.com',
            'value_norm' => 'scammer@evil.com',
            'misp' => [
                'category' => 'Network activity',
                'type' => 'email-src',
                'to_ids' => true,
            ],
            'category' => 'PHISHING',
            'score' => ['agg' => 75],
            'source' => 'regex',
            'tlp' => 'AMBER',
            'tags' => ['honeypot'],
        ]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$ioc]);

        $controller = new ExportMispController($this->iocHandler);
        $response = $controller->__invoke('conv-123');

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('Event', $data);
        $event = $data['Event'];
        $this->assertSame('ScamBuster conversation conv-123', $event['info']);
        $this->assertSame(2, $event['threat_level_id']);
        $this->assertCount(1, $event['Attribute']);

        $attr = $event['Attribute'][0];
        $this->assertSame('Network activity', $attr['category']);
        $this->assertSame('email-src', $attr['type']);
        $this->assertSame('scammer@evil.com', $attr['value']);
        $this->assertTrue($attr['to_ids']);

        // Check comment
        $this->assertStringContainsString('Scam type: PHISHING', $attr['comment']);
        $this->assertStringContainsString('Risk score: 75/100', $attr['comment']);

        // Check tags
        $tagNames = array_column($attr['Tag'], 'name');
        $this->assertContains('tlp:amber', $tagNames);
        $this->assertContains('scam:type=PHISHING', $tagNames);
        $this->assertContains('honeypot', $tagNames);
    }

    public function test_skips_iocs_without_misp_metadata(): void
    {
        $iocWithMisp = $this->createMock(ObservedIoc::class);
        $iocWithMisp->method('getContext')->willReturn([
            'type' => 'url',
            'value' => 'https://evil.com',
            'value_norm' => 'https://evil.com',
            'misp' => ['category' => 'Network activity', 'type' => 'url', 'to_ids' => true],
        ]);

        $iocWithoutMisp = $this->createMock(ObservedIoc::class);
        $iocWithoutMisp->method('getContext')->willReturn([
            'type' => 'phone',
            'value' => '+1234567890',
        ]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$iocWithMisp, $iocWithoutMisp]);

        $controller = new ExportMispController($this->iocHandler);
        $response = $controller->__invoke('conv-123');

        $data = json_decode($response->getContent(), true);
        $this->assertCount(1, $data['Event']['Attribute']);
    }

    public function test_builds_default_comment_when_no_context(): void
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn([
            'type' => 'domain',
            'value' => 'evil.com',
            'value_norm' => 'evil.com',
            'misp' => ['category' => 'Network activity', 'type' => 'domain', 'to_ids' => true],
        ]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$ioc]);

        $controller = new ExportMispController($this->iocHandler);
        $response = $controller->__invoke('conv-123');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('ScamBuster honeypot IOC', $data['Event']['Attribute'][0]['comment']);
    }

    public function test_no_audit_logger_does_not_throw(): void
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn([
            'type' => 'email',
            'value_norm' => 'test@test.com',
            'misp' => ['category' => 'Network activity', 'type' => 'email-src', 'to_ids' => true],
        ]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$ioc]);

        // No audit logger (null) - should not throw
        $controller = new ExportMispController($this->iocHandler, null);
        $response = $controller->__invoke('conv-123');
        $this->assertSame(200, $response->getStatusCode());
    }
}
