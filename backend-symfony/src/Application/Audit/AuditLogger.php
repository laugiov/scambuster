<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Audit\Port\RequestContextInterface;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Persists structured audit events to the audit_log table.
 *
 * Automatically captures trace_id and IP from current request.
 * Optionally forwards events to SIEM via SiemExporterInterface.
 *
 * Computes HMAC chain (prev_hmac + row_hmac) for tamper-evidence.
 * SIEM export is synchronous (blocking) for AUTH_* and INJECTION_DETECTED
 * events; non-blocking for all other events.
 */
final readonly class AuditLogger implements AuditLoggerInterface
{
    /** @var list<string> Event types where SIEM export failure is blocking */
    private const BLOCKING_SIEM_EVENTS = [
        'AUTH_SUCCESS',
        'AUTH_FAILURE',
        'AUTH_TOKEN_EXPIRED',
        'AUTH_LOGOUT',
        // Rotated-token replay is rare + critical: its SIEM delivery must not be silently
        // dropped. AUTH_TOKEN_REFRESHED is deliberately NOT blocking (hot path, ~15-min cadence).
        'AUTH_TOKEN_REUSE_DETECTED',
        'INJECTION_DETECTED',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private RequestContextInterface $requestContext,
        private SiemExporterInterface $siemExporter,
        // HMAC chainer (optional for backward compat)
        private ?AuditHmacChainer $hmacChainer = null,
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
            $ipAddress ??= $this->requestContext->getClientIp();
            $traceId ??= $this->requestContext->getTraceId();

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

            // Compute HMAC chain before persist (only if key is configured)
            if ($this->hmacChainer instanceof \App\Application\Audit\AuditHmacChainer && $this->hmacChainer->isEnabled()) {
                $this->computeHmacChain($entry);
            }

            $this->em->persist($entry);
            $this->em->flush();

            // Forward to SIEM
            $siemEvent = new SiemEvent(
                timestamp: $entry->getCreatedAt(),
                eventType: $eventType,
                severity: SiemSeverityMap::getSeverity($eventType),
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

            // Synchronous SIEM for critical events
            $isBlocking = in_array($eventType->value, self::BLOCKING_SIEM_EVENTS, true);

            try {
                $this->siemExporter->export($siemEvent);
            } catch (\Throwable $siemError) {
                if ($isBlocking) {
                    // Critical event: SIEM failure must NOT be silenced
                    throw new \RuntimeException(
                        'SIEM export failed for critical audit event ' . $eventType->value,
                        0,
                        $siemError,
                    );
                }

                // Non-critical event: log warning, continue
                $this->logger->warning('[AuditLogger] SIEM export failed (non-blocking)', [
                    'event_type' => $eventType->value,
                    'error' => $siemError->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            // For blocking SIEM events, the exception
            // propagates to the caller (e.g., LoginController → HTTP 500).
            // For all other events, the existing non-blocking behavior
            // is preserved.
            if (in_array($eventType->value, self::BLOCKING_SIEM_EVENTS, true)) {
                throw $e;
            }

            $this->logger->warning('[AuditLogger] Failed to persist audit event', [
                'event_type' => $eventType->value,
                'actor_id' => $actorId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compute the HMAC chain for a new audit entry.
     *
     * Queries the latest row_hmac from audit_log (the previous chain
     * head) and computes the new row's HMAC using AuditHmacChainer.
     */
    private function computeHmacChain(AuditLog $entry): void
    {
        /** @var Connection $conn */
        $conn = $this->em->getConnection();

        // Get the latest row_hmac (chain head)
        $latestHmac = $conn->fetchOne(
            'SELECT row_hmac FROM audit_log ORDER BY id DESC LIMIT 1',
        );

        $prevHmacBin = '';

        if ($latestHmac !== false) {
            $prevHmacBin = is_resource($latestHmac) ? (stream_get_contents($latestHmac) ?: '') : (is_string($latestHmac) ? $latestHmac : '');
        }

        $canonicalRow = $entry->toCanonicalRow();
        $newHmac = $this->hmacChainer?->compute($prevHmacBin, $canonicalRow);

        if ($newHmac === null) {
            return;
        }

        $entry->setPrevHmac($prevHmacBin === '' ? null : $prevHmacBin);
        $entry->setRowHmac($newHmac);
    }
}
