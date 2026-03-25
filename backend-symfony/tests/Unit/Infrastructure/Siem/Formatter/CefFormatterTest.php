<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Formatter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Formatter\CefFormatter;
use PHPUnit\Framework\TestCase;

class CefFormatterTest extends TestCase
{
    private CefFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CefFormatter();
    }

    public function testGetFormatName(): void
    {
        $this->assertSame('cef', $this->formatter->getFormatName());
    }

    public function testFormatProducesValidCefHeader(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $output = $this->formatter->format($event);

        $this->assertStringStartsWith('CEF:0|ScamBuster|HoneypotPlatform|1.0|', $output);
    }

    public function testFormatContainsEventTypeInHeader(): void
    {
        $event = $this->createEvent(AuditEventType::INJECTION_DETECTED);
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('|INJECTION_DETECTED|', $output);
    }

    public function testFormatContainsHumanReadableName(): void
    {
        $event = $this->createEvent(AuditEventType::INJECTION_DETECTED);
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('Prompt Injection Detected', $output);
    }

    public function testFormatContainsSeverity(): void
    {
        $event = $this->createEvent(AuditEventType::KILL_SWITCH_TOGGLED, severity: 9);
        $output = $this->formatter->format($event);

        // Severity is the 7th pipe-delimited field
        $parts = explode('|', $output);
        $this->assertSame('9', $parts[6]);
    }

    public function testFormatContainsTimestampExtension(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $output = $this->formatter->format($event);

        $this->assertMatchesRegularExpression('/rt=\d+000/', $output);
    }

    public function testFormatContainsOutcomeExtension(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, outcome: 'success');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('outcome=success', $output);
    }

    public function testFormatContainsActorExtensions(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, actorId: 'user-42', actorType: 'human');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('suser=user-42', $output);
        $this->assertStringContainsString('suid=human', $output);
    }

    public function testFormatContainsIpWhenProvided(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_FAILURE, ipAddress: '10.0.0.1');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('src=10.0.0.1', $output);
    }

    public function testFormatOmitsIpWhenNull(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, ipAddress: null);
        $output = $this->formatter->format($event);

        $this->assertStringNotContainsString('src=', $output);
    }

    public function testFormatContainsTraceIdWhenProvided(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, traceId: 'abc-123');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('cs1=abc-123', $output);
        $this->assertStringContainsString('cs1Label=TraceID', $output);
    }

    public function testFormatContainsResourceWhenProvided(): void
    {
        $event = $this->createEvent(AuditEventType::IOC_EXTRACTED, resourceType: 'conversation', resourceId: 'conv-99');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('cs2=conversation', $output);
        $this->assertStringContainsString('cs2Label=ResourceType', $output);
        $this->assertStringContainsString('cs3=conv-99', $output);
        $this->assertStringContainsString('cs3Label=ResourceID', $output);
    }

    public function testFormatContainsDetailsAsJson(): void
    {
        $event = $this->createEvent(AuditEventType::IOC_EXTRACTED, details: ['ioc_type' => 'iban', 'count' => 3]);
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('msg=', $output);
        $this->assertStringContainsString('iban', $output);
    }

    public function testFormatOmitsDetailsWhenEmpty(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, details: []);
        $output = $this->formatter->format($event);

        $this->assertStringNotContainsString('msg=', $output);
    }

    public function testEscapesPipeInValues(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, actorId: 'user|special');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('suser=user\\|special', $output);
    }

    public function testEscapesEqualsInValues(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, outcome: 'key=value');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('outcome=key\\=value', $output);
    }

    public function testEscapesBackslashInValues(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, actorId: 'user\\admin');
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('suser=user\\\\admin', $output);
    }

    public function testEscapesNewlineInValues(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, outcome: "line1\nline2");
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('outcome=line1\\nline2', $output);
        $this->assertStringNotContainsString("\n", $output);
    }

    /**
     * @dataProvider allEventTypesProvider
     */
    public function testFormatHandlesAllEventTypes(AuditEventType $type): void
    {
        $event = $this->createEvent($type);
        $output = $this->formatter->format($event);

        // Must produce valid CEF: 8 pipe-delimited sections in the header
        $headerEnd = strpos($output, '|', strpos($output, '|', strpos($output, '|', strpos($output, '|', strpos($output, '|', strpos($output, '|', strpos($output, '|') + 1) + 1) + 1) + 1) + 1) + 1);
        $this->assertNotFalse($headerEnd, "CEF header should have 7+ pipe delimiters for event type {$type->value}");
    }

    /**
     * @return iterable<string, array{AuditEventType}>
     */
    public static function allEventTypesProvider(): iterable
    {
        foreach (AuditEventType::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function createEvent(
        AuditEventType $type,
        int $severity = 3,
        string $actorType = 'system',
        string $actorId = 'test-actor',
        string $action = 'test-action',
        string $outcome = 'success',
        array $details = [],
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $ipAddress = null,
        ?string $traceId = null,
    ): SiemEvent {
        return new SiemEvent(
            timestamp: new \DateTimeImmutable('2026-01-15T10:30:00+00:00'),
            eventType: $type,
            severity: $severity,
            actorType: $actorType,
            actorId: $actorId,
            action: $action,
            outcome: $outcome,
            details: $details,
            resourceType: $resourceType,
            resourceId: $resourceId,
            ipAddress: $ipAddress,
            traceId: $traceId,
        );
    }
}
