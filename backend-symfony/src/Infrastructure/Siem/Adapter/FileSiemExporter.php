<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Adapter;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\SiemEvent;
use Psr\Log\LoggerInterface;

/**
 * SIEM exporter that writes events to an NDJSON file.
 *
 * Use cases:
 * - Air-gapped environments without network access to SIEM
 * - Testing and validation of SIEM event formats
 * - Offline import into any SIEM platform
 *
 * Configuration:
 * - SIEM_PROVIDER=file
 * - SIEM_ENDPOINT=/var/log/scambuster/siem-events.ndjson
 */
final class FileSiemExporter implements SiemExporterInterface
{
    public function __construct(
        private readonly SiemEventFormatterInterface $formatter,
        private readonly LoggerInterface $logger,
        private readonly string $filePath,
    ) {
    }

    public function export(SiemEvent $event): void
    {
        try {
            $line = $this->formatter->format($event) . "\n";
            $dir = \dirname($this->filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            $this->logger->warning('[SIEM:file] Failed to write event', [
                'event_type' => $event->eventType->value,
                'file' => $this->filePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function exportBatch(array $events): void
    {
        $lines = '';

        foreach ($events as $event) {
            $lines .= $this->formatter->format($event) . "\n";
        }

        try {
            $dir = \dirname($this->filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }

            file_put_contents($this->filePath, $lines, FILE_APPEND | LOCK_EX);

            $this->logger->info('[SIEM:file] Batch exported', [
                'count' => \count($events),
                'file' => $this->filePath,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[SIEM:file] Failed to write batch', [
                'count' => \count($events),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isHealthy(): bool
    {
        $dir = \dirname($this->filePath);

        return is_dir($dir) && is_writable($dir);
    }

    public function getProviderName(): string
    {
        return 'file';
    }
}
