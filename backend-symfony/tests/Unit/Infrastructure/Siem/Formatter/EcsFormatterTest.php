<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Siem\Formatter;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Infrastructure\Siem\Formatter\EcsFormatter;
use PHPUnit\Framework\TestCase;

class EcsFormatterTest extends TestCase
{
    private EcsFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EcsFormatter();
    }

    public function testGetFormatName(): void
    {
        $this->assertSame('ecs', $this->formatter->getFormatName());
    }

    public function testFormatProducesValidJson(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $output = $this->formatter->format($event);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('@timestamp', $decoded);
        $this->assertArrayHasKey('event', $decoded);
        $this->assertArrayHasKey('message', $decoded);
    }

    public function testFormatContainsIso8601Timestamp(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $output = $this->formatter->format($event);
        $decoded = json_decode($output, true);

        $this->assertSame('2026-01-15T10:30:00+00:00', $decoded['@timestamp']);
    }

    public function testFormatContainsEventFields(): void
    {
        $event = $this->createEvent(AuditEventType::INJECTION_DETECTED, severity: 8);
        $output = $this->formatter->format($event);
        $decoded = json_decode($output, true);

        $this->assertSame('event', $decoded['event']['kind']);
        $this->assertSame('test-action', $decoded['event']['action']);
        $this->assertSame('success', $decoded['event']['outcome']);
        $this->assertSame(8, $decoded['event']['severity']);
        $this->assertSame('scambuster', $decoded['event']['module']);
        $this->assertSame('scambuster.audit', $decoded['event']['dataset']);
        $this->assertSame('INJECTION_DETECTED', $decoded['event']['original']);
    }

    public function testFormatMapsEcsCategory(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['authentication'], $decoded['event']['category']);

        $event = $this->createEvent(AuditEventType::INJECTION_DETECTED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['intrusion_detection'], $decoded['event']['category']);

        $event = $this->createEvent(AuditEventType::IOC_EXTRACTED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['threat'], $decoded['event']['category']);
    }

    public function testFormatMapsEcsEventType(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['start'], $decoded['event']['type']);

        $event = $this->createEvent(AuditEventType::AUTH_LOGOUT);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['end'], $decoded['event']['type']);

        $event = $this->createEvent(AuditEventType::INJECTION_DETECTED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['indicator'], $decoded['event']['type']);

        $event = $this->createEvent(AuditEventType::RATE_LIMIT_EXCEEDED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['denied'], $decoded['event']['type']);

        $event = $this->createEvent(AuditEventType::CONFIG_CHANGED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['change'], $decoded['event']['type']);

        $event = $this->createEvent(AuditEventType::REPLY_GENERATED);
        $decoded = json_decode($this->formatter->format($event), true);
        $this->assertSame(['info'], $decoded['event']['type']);
    }

    public function testFormatContainsUserInfo(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, actorId: 'user-42', actorType: 'human');
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('user-42', $decoded['user']['id']);
        $this->assertSame('human', $decoded['user']['type']);
    }

    public function testFormatContainsSourceIpWhenProvided(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_FAILURE, ipAddress: '192.168.1.1');
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('192.168.1.1', $decoded['source']['ip']);
    }

    public function testFormatOmitsSourceIpWhenNull(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, ipAddress: null);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertArrayNotHasKey('source', $decoded);
    }

    public function testFormatContainsTraceIdWhenProvided(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, traceId: 'trace-abc');
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('trace-abc', $decoded['trace']['id']);
    }

    public function testFormatOmitsTraceWhenNull(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, traceId: null);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertArrayNotHasKey('trace', $decoded);
    }

    public function testFormatContainsLabelsForResource(): void
    {
        $event = $this->createEvent(AuditEventType::IOC_EXTRACTED, resourceType: 'message', resourceId: 'msg-1');
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('message', $decoded['labels']['resource_type']);
        $this->assertSame('msg-1', $decoded['labels']['resource_id']);
    }

    public function testFormatOmitsLabelsWhenNoResource(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertArrayNotHasKey('labels', $decoded);
    }

    public function testFormatContainsDetailsInScambusterField(): void
    {
        $event = $this->createEvent(AuditEventType::IOC_EXTRACTED, details: ['ioc_type' => 'iban', 'value' => 'DE89...']);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertSame('iban', $decoded['scambuster']['ioc_type']);
        $this->assertSame('DE89...', $decoded['scambuster']['value']);
    }

    public function testFormatOmitsScambusterFieldWhenDetailsEmpty(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_SUCCESS, details: []);
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertArrayNotHasKey('scambuster', $decoded);
    }

    public function testFormatMessageContainsAllKeyInfo(): void
    {
        $event = $this->createEvent(AuditEventType::AUTH_FAILURE, actorType: 'human', actorId: 'admin', action: 'login', outcome: 'failure');
        $decoded = json_decode($this->formatter->format($event), true);

        $this->assertStringContainsString('AUTH_FAILURE', $decoded['message']);
        $this->assertStringContainsString('login', $decoded['message']);
        $this->assertStringContainsString('human:admin', $decoded['message']);
        $this->assertStringContainsString('failure', $decoded['message']);
    }

    /**
     * @dataProvider allEventTypesProvider
     */
    public function testFormatHandlesAllEventTypes(AuditEventType $type): void
    {
        $event = $this->createEvent($type);
        $output = $this->formatter->format($event);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, "ECS format must produce valid JSON for {$type->value}");
        $this->assertArrayHasKey('event', $decoded);
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
