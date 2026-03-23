<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\EventListener\Security\TraceIdListener;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Persists structured audit events to the audit_log table.
 *
 * Automatically captures trace_id and IP from current request.
 * Non-blocking: errors are logged to Monolog but never thrown.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack
    ) {
    }

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
    ): void {
        try {
            $request = $this->requestStack->getCurrentRequest();

            if ($ipAddress === null && $request !== null) {
                $ipAddress = $request->getClientIp();
            }

            if ($traceId === null && $request !== null) {
                $traceId = TraceIdListener::getTraceId($request);
            }

            $entry = new AuditLog(
                eventType: $eventType,
                actorType: $actorType,
                actorId: $actorId,
                action: $action,
                outcome: $outcome,
                details: $details,
                resourceType: $resourceType,
                resourceId: $resourceId,
                ipAddress: $ipAddress,
                traceId: $traceId
            );

            $this->em->persist($entry);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('[AuditLogger] Failed to persist audit event', [
                'event_type' => $eventType->value,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
