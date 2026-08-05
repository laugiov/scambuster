<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use PHPUnit\Framework\TestCase;

class AuditLogTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $log = new AuditLog(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorType: 'user',
            actorId: 'admin@example.com',
            action: 'authenticate',
            outcome: 'success',
            details: ['method' => 'jwt'],
            resourceType: 'token',
            resourceId: 'tok-123',
            ipAddress: '192.168.1.1',
            traceId: 'trace-abc'
        );

        $this->assertSame('AUTH_SUCCESS', $log->getEventType());
        $this->assertSame('user', $log->getActorType());
        $this->assertSame('admin@example.com', $log->getActorId());
        $this->assertSame('authenticate', $log->getAction());
        $this->assertSame('success', $log->getOutcome());
        $this->assertSame(['method' => 'jwt'], $log->getDetails());
        $this->assertSame('token', $log->getResourceType());
        $this->assertSame('tok-123', $log->getResourceId());
        $this->assertSame('192.168.1.1', $log->getIpAddress());
        $this->assertSame('trace-abc', $log->getTraceId());
        $this->assertInstanceOf(\DateTimeImmutable::class, $log->getCreatedAt());
    }

    public function testConstructionWithMinimalFields(): void
    {
        $log = new AuditLog(
            eventType: AuditEventType::MESSAGE_INGESTED,
            actorType: 'system',
            actorId: 'n8n-workflow',
            action: 'create',
            outcome: 'success'
        );

        $this->assertSame('MESSAGE_INGESTED', $log->getEventType());
        $this->assertNull($log->getResourceType());
        $this->assertNull($log->getResourceId());
        $this->assertNull($log->getIpAddress());
        $this->assertNull($log->getTraceId());
        $this->assertSame([], $log->getDetails());
    }

    public function testToArrayReturnsCompleteStructure(): void
    {
        $log = new AuditLog(
            eventType: AuditEventType::IOC_EXTRACTED,
            actorType: 'service',
            actorId: 'ioc-extractor',
            action: 'extract',
            outcome: 'success',
            resourceType: 'conversation',
            resourceId: 'conv-uuid-123'
        );

        $array = $log->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('event_type', $array);
        $this->assertArrayHasKey('actor_type', $array);
        $this->assertArrayHasKey('actor_id', $array);
        $this->assertArrayHasKey('action', $array);
        $this->assertArrayHasKey('outcome', $array);
        $this->assertArrayHasKey('details', $array);
        $this->assertArrayHasKey('created_at', $array);
        $this->assertSame('IOC_EXTRACTED', $array['event_type']);
        $this->assertSame('conv-uuid-123', $array['resource_id']);
    }

    public function testAllEventTypesAreValid(): void
    {
        $cases = AuditEventType::cases();
        $this->assertGreaterThanOrEqual(15, count($cases));

        foreach ($cases as $case) {
            $this->assertNotEmpty($case->value);
        }
    }
}
