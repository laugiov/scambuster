<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

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

        $logger = new AuditLogger($em, new NullLogger(), new RequestStack());

        $logger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: 'admin@example.com',
            action: 'authenticate',
            outcome: 'success'
        );
    }

    public function testLogHandlesExceptionGracefully(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('DB down'));

        $logger = new AuditLogger($em, new NullLogger(), new RequestStack());

        // Should not throw — non-blocking
        $logger->log(
            eventType: AuditEventType::AUTH_FAILURE,
            actorId: 'anonymous',
            action: 'authenticate',
            outcome: 'failure'
        );

        $this->assertTrue(true); // Reached here = no exception
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

        $logger = new AuditLogger($em, new NullLogger(), new RequestStack());

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
