<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use PHPUnit\Framework\TestCase;

class SiemEventTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $ts = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $event = new SiemEvent(
            timestamp: $ts,
            eventType: AuditEventType::IOC_EXTRACTED,
            severity: 4,
            actorType: 'system',
            actorId: 'ioc-extractor',
            action: 'extract_ioc',
            outcome: 'success',
            details: ['ioc_type' => 'iban'],
            resourceType: 'message',
            resourceId: 'msg-42',
            ipAddress: '10.0.0.1',
            traceId: 'trace-abc',
        );

        $this->assertSame($ts, $event->timestamp);
        $this->assertSame(AuditEventType::IOC_EXTRACTED, $event->eventType);
        $this->assertSame(4, $event->severity);
        $this->assertSame('system', $event->actorType);
        $this->assertSame('ioc-extractor', $event->actorId);
        $this->assertSame('extract_ioc', $event->action);
        $this->assertSame('success', $event->outcome);
        $this->assertSame(['ioc_type' => 'iban'], $event->details);
        $this->assertSame('message', $event->resourceType);
        $this->assertSame('msg-42', $event->resourceId);
        $this->assertSame('10.0.0.1', $event->ipAddress);
        $this->assertSame('trace-abc', $event->traceId);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $event = new SiemEvent(
            timestamp: new \DateTimeImmutable(),
            eventType: AuditEventType::AUTH_SUCCESS,
            severity: 1,
            actorType: 'system',
            actorId: 'test',
            action: 'login',
            outcome: 'success',
            details: [],
        );

        $this->assertNull($event->resourceType);
        $this->assertNull($event->resourceId);
        $this->assertNull($event->ipAddress);
        $this->assertNull($event->traceId);
    }

    public function testFromAuditLogCreatesCorrectEvent(): void
    {
        $log = $this->createMock(\App\Domain\Audit\AuditLog::class);
        $ts = new \DateTimeImmutable('2026-01-15 10:00:00');
        $log->method('getCreatedAt')->willReturn($ts);
        $log->method('getEventType')->willReturn('AUTH_SUCCESS');
        $log->method('getActorType')->willReturn('user');
        $log->method('getActorId')->willReturn('admin@test.com');
        $log->method('getAction')->willReturn('login');
        $log->method('getOutcome')->willReturn('success');
        $log->method('getDetails')->willReturn(['browser' => 'Chrome']);
        $log->method('getResourceType')->willReturn('session');
        $log->method('getResourceId')->willReturn('s-1');
        $log->method('getIpAddress')->willReturn('10.0.0.1');
        $log->method('getTraceId')->willReturn('t-1');

        $event = SiemEvent::fromAuditLog($log);

        $this->assertSame($ts, $event->timestamp);
        $this->assertSame(AuditEventType::AUTH_SUCCESS, $event->eventType);
        $this->assertSame('admin@test.com', $event->actorId);
        $this->assertSame(['browser' => 'Chrome'], $event->details);
        $this->assertSame('10.0.0.1', $event->ipAddress);
    }

    public function testIsImmutable(): void
    {
        $event = new SiemEvent(
            timestamp: new \DateTimeImmutable(),
            eventType: AuditEventType::AUTH_SUCCESS,
            severity: 1,
            actorType: 'system',
            actorId: 'test',
            action: 'login',
            outcome: 'success',
            details: ['key' => 'value'],
        );

        // Readonly properties — this test documents the contract
        $reflection = new \ReflectionClass($event);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly");
        }
    }
}
