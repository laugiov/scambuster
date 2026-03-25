<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Adapter;

use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\SiemEvent;

/**
 * No-op SIEM exporter (default when SIEM_PROVIDER=none).
 *
 * Zero overhead: all methods are no-ops.
 * This is the default adapter — no external dependencies, no configuration.
 */
final class NullSiemExporter implements SiemExporterInterface
{
    public function export(SiemEvent $event): void
    {
        // Intentionally empty — SIEM export disabled
    }

    public function exportBatch(array $events): void
    {
        // Intentionally empty
    }

    public function isHealthy(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'none';
    }
}
