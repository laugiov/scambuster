<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Adapter;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Domain\Audit\SiemEvent;
use Psr\Log\LoggerInterface;

/**
 * SIEM exporter that sends events via syslog (UDP/TCP).
 *
 * Supports RFC 5424 syslog with CEF or LEEF message body.
 *
 * Configuration:
 * - SIEM_PROVIDER=syslog
 * - SIEM_ENDPOINT=udp://10.0.0.1:514 or tcp://siem.local:514
 */
final class SyslogSiemExporter implements SiemExporterInterface
{
    private const FACILITY_LOCAL0 = 16;
    private const APP_NAME = 'ScamBuster';

    public function __construct(
        private readonly SiemEventFormatterInterface $formatter,
        private readonly LoggerInterface $logger,
        private readonly string $endpoint,
    ) {
    }

    public function export(SiemEvent $event): void
    {
        try {
            $message = $this->buildSyslogMessage($event);
            $this->send($message);
        } catch (\Throwable $e) {
            $this->logger->warning('[SIEM:syslog] Failed to send event', [
                'event_type' => $event->eventType->value,
                'endpoint' => $this->endpoint,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function exportBatch(array $events): void
    {
        foreach ($events as $event) {
            $this->export($event);
        }

        $this->logger->info('[SIEM:syslog] Batch exported', [
            'count' => \count($events),
            'endpoint' => $this->endpoint,
        ]);
    }

    public function isHealthy(): bool
    {
        $parsed = $this->parseEndpoint();

        if ($parsed === null) {
            return false;
        }

        $socket = @fsockopen(
            $parsed['protocol'] . '://' . $parsed['host'],
            $parsed['port'],
            $errno,
            $errstr,
            3,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public function getProviderName(): string
    {
        return 'syslog';
    }

    /**
     * Build RFC 5424 syslog message with formatted body.
     *
     * Format: <PRI>1 TIMESTAMP HOSTNAME APP-NAME PROCID MSGID STRUCTURED-DATA MSG
     */
    private function buildSyslogMessage(SiemEvent $event): string
    {
        $severity = min($event->severity, 7); // syslog severity 0-7
        $facility = self::FACILITY_LOCAL0;
        $priority = ($facility * 8) + $severity;

        $timestamp = $event->timestamp->format('Y-m-d\TH:i:s.vP');
        $hostname = gethostname() ?: 'scambuster';
        $msgBody = $this->formatter->format($event);

        return sprintf(
            '<%d>1 %s %s %s - - - %s',
            $priority,
            $timestamp,
            $hostname,
            self::APP_NAME,
            $msgBody,
        );
    }

    private function send(string $message): void
    {
        $parsed = $this->parseEndpoint();

        if ($parsed === null) {
            throw new \RuntimeException('Invalid SIEM_ENDPOINT: ' . $this->endpoint);
        }

        $socket = @fsockopen(
            $parsed['protocol'] . '://' . $parsed['host'],
            $parsed['port'],
            $errno,
            $errstr,
            5,
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf('Syslog connection failed: %s (%d)', $errstr, $errno));
        }

        fwrite($socket, $message);
        fclose($socket);
    }

    /**
     * @return array{protocol: string, host: string, port: int}|null
     */
    private function parseEndpoint(): ?array
    {
        if (!preg_match('#^(udp|tcp)://([^:]+):(\d+)$#', $this->endpoint, $m)) {
            return null;
        }

        return [
            'protocol' => $m[1],
            'host' => $m[2],
            'port' => (int) $m[3],
        ];
    }
}
