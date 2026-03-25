<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Normalized security event ready for SIEM export.
 *
 * Immutable value object that encapsulates all data needed
 * to format an audit event for any SIEM platform (CEF, LEEF, ECS, Syslog).
 */
final class SiemEvent
{
    public function __construct(
        public readonly \DateTimeImmutable $timestamp,
        public readonly AuditEventType $eventType,
        public readonly int $severity,
        public readonly string $actorType,
        public readonly string $actorId,
        public readonly string $action,
        public readonly string $outcome,
        /** @var array<string, mixed> */
        public readonly array $details,
        public readonly ?string $resourceType = null,
        public readonly ?string $resourceId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $traceId = null,
    ) {
    }

    /**
     * Create from an AuditLog entity.
     */
    public static function fromAuditLog(AuditLog $log): self
    {
        return new self(
            timestamp: $log->getCreatedAt(),
            eventType: AuditEventType::from($log->getEventType()),
            severity: SiemSeverityMap::getSeverity(AuditEventType::from($log->getEventType())),
            actorType: $log->getActorType(),
            actorId: $log->getActorId(),
            action: $log->getAction(),
            outcome: $log->getOutcome(),
            details: $log->getDetails(),
            resourceType: $log->getResourceType(),
            resourceId: $log->getResourceId(),
            ipAddress: $log->getIpAddress(),
            traceId: $log->getTraceId(),
        );
    }
}
