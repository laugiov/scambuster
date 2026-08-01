<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Formatter;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;

/**
 * Formats SiemEvents as ECS (Elastic Common Schema) 8.x JSON.
 *
 * ECS is the standard for Elastic Security, Filebeat, and Logstash.
 * Each event is a single JSON object (one line for NDJSON compatibility).
 *
 * Reference: https://www.elastic.co/guide/en/ecs/current/index.html
 */
final class EcsFormatter implements SiemEventFormatterInterface
{
    public function format(SiemEvent $event): string
    {
        $ecs = [
            '@timestamp' => $event->timestamp->format(\DateTimeInterface::ATOM),
            'event' => [
                'kind' => 'event',
                'category' => [SiemSeverityMap::getEcsCategory($event->eventType)],
                'type' => [$this->getEcsEventType($event)],
                'action' => $event->action,
                'outcome' => $event->outcome,
                'severity' => $event->severity,
                'module' => 'scambuster',
                'dataset' => 'scambuster.audit',
                'original' => $event->eventType->value,
            ],
            'message' => $this->buildMessage($event),
        ];

        if ($event->ipAddress !== null) {
            $ecs['source'] = ['ip' => $event->ipAddress];
        }

        $ecs['user'] = [
            'id' => $event->actorId,
            'type' => $event->actorType,
        ];

        if ($event->traceId !== null) {
            $ecs['trace'] = ['id' => $event->traceId];
        }

        if ($event->resourceType !== null || $event->resourceId !== null) {
            $ecs['labels'] = array_filter([
                'resource_type' => $event->resourceType,
                'resource_id' => $event->resourceId,
            ]);
        }

        if ($event->details !== []) {
            $ecs['scambuster'] = $event->details;
        }

        return json_encode($ecs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function getFormatName(): string
    {
        return 'ecs';
    }

    private function getEcsEventType(SiemEvent $event): string
    {
        return match ($event->eventType->value) {
            'AUTH_SUCCESS' => 'start',
            'AUTH_FAILURE' => 'start',
            'AUTH_LOGOUT' => 'end',
            'AUTH_TOKEN_EXPIRED' => 'info',
            'INJECTION_DETECTED' => 'indicator',
            'IOC_EXTRACTED' => 'indicator',
            'TTP_EXTRACTED' => 'indicator',
            'RATE_LIMIT_EXCEEDED' => 'denied',
            'KILL_SWITCH_TOGGLED' => 'change',
            'CONFIG_CHANGED' => 'change',
            default => 'info',
        };
    }

    private function buildMessage(SiemEvent $event): string
    {
        return sprintf(
            '[%s] %s by %s:%s — %s',
            $event->eventType->value,
            $event->action,
            $event->actorType,
            $event->actorId,
            $event->outcome,
        );
    }
}
