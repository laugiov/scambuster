<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Communication;

use App\Application\Communication\IocExportMapper;
use App\Application\Communication\IocHandler;
use App\Application\ThreatActor\IocFeedbackReaderInterface;
use App\Domain\Communication\ObservedIoc;
use App\Domain\ThreatActor\AnalystVerdict;
use App\UI\Http\Communication\ExportMispController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ExportMispControllerTest extends TestCase
{
    private IocHandler&MockObject $iocHandler;

    /** Real mapper: it is a pure type-to-MISP mapping, nothing to stub. */
    private IocExportMapper $exportMapper;

    protected function setUp(): void
    {
        $this->iocHandler = $this->createMock(IocHandler::class);
        $this->exportMapper = new IocExportMapper();
    }

    public function test_returns_404_when_no_iocs(): void
    {
        $this->iocHandler->method('getConversationIocs')->willReturn([]);

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper);
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

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper);
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

    /**
     * This used to assert the opposite — that an IOC without stored MISP
     * metadata is skipped — which pinned the bug in place: on the seeded demo
     * dataset that is every IOC, so the endpoint returned HTTP 200 with an
     * empty Event and no indication anything had been dropped. The mapping is a
     * pure function of the IOC type, so it is derived on the fly instead.
     */
    public function test_derives_misp_metadata_when_missing_instead_of_skipping(): void
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
            'value_norm' => '+1234567890',
        ]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$iocWithMisp, $iocWithoutMisp]);

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper);
        $response = $controller->__invoke('conv-123');

        $data = json_decode($response->getContent(), true);
        $attributes = $data['Event']['Attribute'];

        $this->assertCount(2, $attributes, 'no IOC may be silently dropped');

        $derived = $attributes[1];
        $this->assertSame('phone-number', $derived['type'], 'the type must come from the IocExportMapper mapping');
        $this->assertSame('Person', $derived['category']);
        $this->assertFalse($derived['to_ids'], 'phone numbers are not auto-actionable');
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

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper);
        $response = $controller->__invoke('conv-123');

        $data = json_decode($response->getContent(), true);
        $this->assertSame('ScamBuster honeypot IOC', $data['Event']['Attribute'][0]['comment']);
    }

    public function test_false_positive_verdict_disables_to_ids_and_tags_the_attribute(): void
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getIndicatorId')->willReturn('ind-fp');
        $ioc->method('getContext')->willReturn([
            'type' => 'domain',
            'value_norm' => 'bogus.example',
            'misp' => ['category' => 'Network activity', 'type' => 'domain', 'to_ids' => true],
        ]);

        $reader = $this->createMock(IocFeedbackReaderInterface::class);
        $reader->method('getVerdicts')->with(['ind-fp'])->willReturn(['ind-fp' => AnalystVerdict::FalsePositive]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$ioc]);

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper, null, null, $reader);
        $data = json_decode($controller->__invoke('conv-123')->getContent(), true);

        $attr = $data['Event']['Attribute'][0];
        // A known false-positive must never be auto-actioned by a downstream MISP consumer.
        $this->assertFalse($attr['to_ids']);
        $tagNames = array_column($attr['Tag'], 'name');
        $this->assertContains('scambuster:analyst-verdict="false_positive"', $tagNames);
    }

    public function test_confirmed_verdict_enables_to_ids_and_tags_the_attribute(): void
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getIndicatorId')->willReturn('ind-ok');
        $ioc->method('getContext')->willReturn([
            'type' => 'domain',
            'value_norm' => 'real.example',
            // to_ids starts false; a confirmed verdict must promote it to actionable.
            'misp' => ['category' => 'Network activity', 'type' => 'domain', 'to_ids' => false],
        ]);

        $reader = $this->createMock(IocFeedbackReaderInterface::class);
        $reader->method('getVerdicts')->willReturn(['ind-ok' => AnalystVerdict::Confirmed]);

        $this->iocHandler->method('getConversationIocs')->willReturn([$ioc]);

        $controller = new ExportMispController($this->iocHandler, $this->exportMapper, null, null, $reader);
        $data = json_decode($controller->__invoke('conv-123')->getContent(), true);

        $attr = $data['Event']['Attribute'][0];
        $this->assertTrue($attr['to_ids']);
        $tagNames = array_column($attr['Tag'], 'name');
        $this->assertContains('scambuster:analyst-verdict="confirmed"', $tagNames);
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
        $controller = new ExportMispController($this->iocHandler, $this->exportMapper, null);
        $response = $controller->__invoke('conv-123');
        $this->assertSame(200, $response->getStatusCode());
    }
}
