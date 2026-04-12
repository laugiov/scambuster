<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;
use App\EventListener\Security\TraceIdListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Persists structured audit events to the audit_log table.
 *
 * Automatically captures trace_id and IP from current request.
 * Optionally forwards events to SIEM via SiemExporterInterface.
 *
 * Spec 065f changes:
 * - Computes HMAC chain (prev_hmac + row_hmac) for tamper-evidence
 * - SIEM export is synchronous (blocking) for AUTH_* and INJECTION_DETECTED
 *   events; non-blocking for all other events
 */
final class AuditLogger
{
    /** @var list<string> Event types where SIEM export failure is blocking */
    private const BLOCKING_SIEM_EVENTS = [
        'AUTH_SUCCESS',
        'AUTH_FAILURE',
        'AUTH_TOKEN_EXPIRED',
        'AUTH_LOGOUT',
        'INJECTION_DETECTED',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly SiemExporterInterface $siemExporter,
        // Spec 065f — HMAC chainer (optional for backward compat)
        private readonly ?AuditHmacChainer $hmacChainer = null,
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

            // Spec 065f — Compute HMAC chain before persist (only if key is configured)
            if ($this->hmacChainer !== null && $this->hmacChainer->isEnabled()) {
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

            // Spec 065f — Synchronous SIEM for critical events
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
            // Spec 065f — For blocking SIEM events, the exception
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
     * Spec 065f — Compute the HMAC chain for a new audit entry.
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
        $newHmac = $this->hmacChainer->compute($prevHmacBin, $canonicalRow);

        $entry->setPrevHmac($prevHmacBin === '' ? null : $prevHmacBin);
        $entry->setRowHmac($newHmac);
    }
}
