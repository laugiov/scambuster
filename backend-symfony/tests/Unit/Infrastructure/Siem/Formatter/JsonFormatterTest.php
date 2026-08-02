<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Formatter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Formatter\JsonFormatter;
use PHPUnit\Framework\TestCase;

class JsonFormatterTest extends TestCase
{
    private JsonFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new JsonFormatter();
    }

    public function testGetFormatName(): void
    {
        $this->assertSame('json', $this->formatter->getFormatName());
    }

    public function testFormatProducesValidJson(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $output = $this->formatter->format($event);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
    }

    public function testFormatProducesSingleLine(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, details: ['key' => "multi\nline"]);
        $output = $this->formatter->format($event);

        // JSON output must be a single line (NDJSON compatible)
        $this->assertStringNotContainsString("\n", $output);
    }

    public function testFormatContainsAllRequiredFields(): void
    {
        $event = $this->createEvent(
            AuditEventType::IOC_EXTRACTED,
            severity: 4,
            actorType: 'system',
            actorId: 'ioc-extractor',
            action: 'extract',
            outcome: 'success',
            details: ['count' => 5],
            resourceType: 'message',
            resourceId: 'msg-42',
            ipAddress: '10.0.0.1',
            traceId: 'trace-xyz',
        );
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('2026-01-15T10:30:00+00:00', $decoded['timestamp']);
        $this->assertSame('IOC_EXTRACTED', $decoded['event_type']);
        $this->assertSame(4, $decoded['severity']);
        $this->assertSame('Medium', $decoded['severity_label']);
        $this->assertSame('threat', $decoded['category']);
        $this->assertSame('system', $decoded['actor_type']);
        $this->assertSame('ioc-extractor', $decoded['actor_id']);
        $this->assertSame('extract', $decoded['action']);
        $this->assertSame('success', $decoded['outcome']);
        $this->assertSame('message', $decoded['resource_type']);
        $this->assertSame('msg-42', $decoded['resource_id']);
        $this->assertSame('10.0.0.1', $decoded['ip_address']);
        $this->assertSame('trace-xyz', $decoded['trace_id']);
        $this->assertSame(['count' => 5], $decoded['details']);
        $this->assertSame('scambuster', $decoded['source']);
    }

    public function testFormatNullableFieldsAreNull(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertNull($decoded['resource_type']);
        $this->assertNull($decoded['resource_id']);
        $this->assertNull($decoded['ip_address']);
        $this->assertNull($decoded['trace_id']);
    }

    public function testFormatSeverityLabels(): void
    {
        $low = $this->createEvent(AuditEventType::AUTH_SUCCESS, severity: 1);
        $this->assertSame('Low', json_decode($this->formatter->format($low), true)['severity_label']);

        $medium = $this->createEvent(AuditEventType::AUTH_FAILURE, severity: 5);
        $this->assertSame('Medium', json_decode($this->formatter->format($medium), true)['severity_label']);

        $high = $this->createEvent(AuditEventType::INJECTION_DETECTED, severity: 8);
        $this->assertSame('High', json_decode($this->formatter->format($high), true)['severity_label']);

        $critical = $this->createEvent(AuditEventType::KILL_SWITCH_TOGGLED, severity: 9);
        $this->assertSame('Critical', json_decode($this->formatter->format($critical), true)['severity_label']);
    }

    public function testFormatEmptyDetailsIsEmptyArray(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, details: []);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame([], $decoded['details']);
    }

    public function testFormatPreservesUnicodeCharacters(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, details: ['name' => 'utilisateur français']);
        $output = $this->formatter->format($event);

        $this->assertStringContainsString('utilisateur français', $output);
    }

    /**
     * @dataProvider allEventTypesProvider
     */
    public function testFormatHandlesAllEventTypes(AuditEventType $type): void
    {
        $event = $this->createEvent($type);
        $output = $this->formatter->format($event);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "JSON format must produce valid JSON for {$type->value}");
        $this->assertSame($type->value, $decoded['event_type']);
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
