<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditEventType;

/**
 * Port for structured audit logging.
 *
 * Introduced so Application services depend on an abstraction rather than the concrete
 * (final) {@see AuditLogger}, which is otherwise un-mockable in unit tests. The concrete
 * logger is the sole implementation, aliased in services.yaml. Existing consumers may keep
 * depending on the concrete class; new code should prefer this port.
 */
interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $details
     */
    public function log(
        AuditEventType $eventType,
        string $actorId,
        string $action,
        string $outcome,
        ?string $resourceType = null,
        ?string $resourceId = null,
        array $details = [],
        ?string $ipAddress = null,
        ?string $traceId = null,
        string $actorType = 'user'
    ): void;
}
