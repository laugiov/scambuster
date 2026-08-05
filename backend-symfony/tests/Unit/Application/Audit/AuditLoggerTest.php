<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AuditLoggerTest extends TestCase
{
    public function testLogPersistsAuditEntry(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(fn (AuditLog $log) => $log->getEventType() === 'AUTH_SUCCESS'
                && $log->getActorId() === 'admin@example.com'
                && $log->getAction() === 'authenticate'
                && $log->getOutcome() === 'success'));

        $em->expects($this->once())->method('flush');

        $logger = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());

        $logger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: 'admin@example.com',
            action: 'authenticate',
            outcome: 'success'
        );
    }

    public function testLogHandlesExceptionGracefullyForNonBlockingEvents(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('DB down'));

        $logger = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());

        // Should not throw — non-blocking event (MESSAGE_INGESTED is not
        // in the BLOCKING_SIEM_EVENTS list)
        $logger->log(
            eventType: AuditEventType::MESSAGE_INGESTED,
            actorId: 'system',
            action: 'ingest',
            outcome: 'failure'
        );

        $this->assertTrue(true); // Reached here = no exception
    }

    /**
     * AUTH events are now blocking: exceptions MUST propagate.
     */
    public function testLogThrowsForBlockingEvents(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('DB down'));

        $logger = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB down');

        $logger->log(
            eventType: AuditEventType::AUTH_FAILURE,
            actorId: 'anonymous',
            action: 'authenticate',
            outcome: 'failure'
        );
    }

    public function testLogPassesAllFieldsToEntity(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(fn (AuditLog $log) => $log->getResourceType() === 'conversation'
                && $log->getResourceId() === 'conv-123'
                && $log->getIpAddress() === '10.0.0.1'
                && $log->getTraceId() === 'trace-xyz'
                && $log->getActorType() === 'service'));

        $em->expects($this->once())->method('flush');

        $logger = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());

        $logger->log(
            eventType: AuditEventType::REPLY_GENERATED,
            actorId: 'reply-orchestrator',
            action: 'generate',
            outcome: 'success',
            resourceType: 'conversation',
            resourceId: 'conv-123',
            details: ['persona' => 'elderly_person'],
            ipAddress: '10.0.0.1',
            traceId: 'trace-xyz',
            actorType: 'service'
        );
    }
}
