<?php

declare(strict_types=1);

namespace App\Application\Audit\Port;

use App\Domain\Audit\SiemEvent;

/**
 * Port for formatting SiemEvents into SIEM-specific string representations.
 *
 * Implementations:
 * - CefFormatter: Common Event Format (ArcSight, Splunk, QRadar)
 * - EcsFormatter: Elastic Common Schema (JSON)
 * - JsonFormatter: Plain NDJSON (universal, file export)
 *
 * Each formatter produces a single string per event.
 */
interface SiemEventFormatterInterface
{
    /**
     * Format a SiemEvent into a string suitable for the target SIEM.
     */
    public function format(SiemEvent $event): string;

    /**
     * Get the format name (for logging and diagnostics).
     */
    public function getFormatName(): string;
}
