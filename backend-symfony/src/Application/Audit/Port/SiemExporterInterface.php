<?php

declare(strict_types=1);

namespace App\Application\Audit\Port;

use App\Domain\Audit\SiemEvent;

/**
 * Port for SIEM event export.
 *
 * Implementations (adapters) handle the transport to specific SIEM platforms:
 * - NullSiemExporter: disabled (default, zero overhead)
 * - FileSiemExporter: NDJSON file output
 * - SyslogSiemExporter: RFC 5424 syslog (UDP/TCP)
 * - SplunkSiemExporter: Splunk HEC (HTTP)
 * - ElasticSiemExporter: Elastic bulk API (HTTP)
 *
 * Selection via SIEM_PROVIDER env var + SiemCompilerPass.
 */
interface SiemExporterInterface
{
    /**
     * Export a single event to the SIEM target.
     *
     * Non-blocking: implementations MUST NOT throw on transport failure.
     * Failures are logged internally and retried if applicable.
     */
    public function export(SiemEvent $event): void;

    /**
     * Export a batch of events (for historical seeding via CLI).
     *
     * @param list<SiemEvent> $events
     */
    public function exportBatch(array $events): void;

    /**
     * Check if the SIEM target is reachable.
     */
    public function isHealthy(): bool;

    /**
     * Get the active provider name (for health checks and logging).
     */
    public function getProviderName(): string;
}
