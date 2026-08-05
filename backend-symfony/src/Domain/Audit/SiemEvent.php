<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Normalized security event ready for SIEM export.
 *
 * Immutable value object that encapsulates all data needed
 * to format an audit event for any SIEM platform (CEF, LEEF, ECS, Syslog).
 */
final readonly class SiemEvent
{
    public function __construct(
        public \DateTimeImmutable $timestamp,
        public AuditEventType $eventType,
        public int $severity,
        public string $actorType,
        public string $actorId,
        public string $action,
        public string $outcome,
        /** @var array<string, mixed> */
        public array $details,
        public ?string $resourceType = null,
        public ?string $resourceId = null,
        public ?string $ipAddress = null,
        public ?string $traceId = null,
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
