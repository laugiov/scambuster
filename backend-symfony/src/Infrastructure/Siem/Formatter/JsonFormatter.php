<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Formatter;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;

/**
 * Formats SiemEvents as plain JSON (NDJSON compatible).
 *
 * Universal format for file export and generic webhook receivers.
 * Each event is a single JSON line.
 */
final class JsonFormatter implements SiemEventFormatterInterface
{
    public function format(SiemEvent $event): string
    {
        $data = [
            'timestamp' => $event->timestamp->format(\DateTimeInterface::ATOM),
            'event_type' => $event->eventType->value,
            'severity' => $event->severity,
            'severity_label' => SiemSeverityMap::getLabel($event->severity),
            'category' => SiemSeverityMap::getEcsCategory($event->eventType),
            'actor_type' => $event->actorType,
            'actor_id' => $event->actorId,
            'action' => $event->action,
            'outcome' => $event->outcome,
            'resource_type' => $event->resourceType,
            'resource_id' => $event->resourceId,
            'ip_address' => $event->ipAddress,
            'trace_id' => $event->traceId,
            'details' => $event->details,
            'source' => 'scambuster',
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getFormatName(): string
    {
        return 'json';
    }
}
