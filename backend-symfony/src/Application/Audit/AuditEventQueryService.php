<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Queries audit_log table and converts rows to SiemEvent DTOs.
 */
class AuditEventQueryService
{
    private readonly Connection $connection;

    public function __construct(EntityManagerInterface $em)
    {
        $this->connection = $em->getConnection();
    }

    /**
     * @return list<SiemEvent>
     */
    public function fetchEventsSince(\DateTimeImmutable $since): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM audit_log WHERE created_at >= :since ORDER BY created_at ASC',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        $events = [];

        foreach ($rows as $row) {
            /** @var array{event_type: string, created_at: string, actor_type: string, actor_id: string, action: string, outcome: string, details: string, resource_type: ?string, resource_id: ?string, ip_address: ?string, trace_id: ?string} $row */
            $events[] = $this->rowToSiemEvent($row);
        }

        return $events;
    }

    /**
     * @param array{event_type: string, created_at: string, actor_type: string, actor_id: string, action: string, outcome: string, details: string, resource_type: ?string, resource_id: ?string, ip_address: ?string, trace_id: ?string} $row
     */
    private function rowToSiemEvent(array $row): SiemEvent
    {
        $eventType = AuditEventType::from($row['event_type']);

        /** @var array<string, mixed> $details */
        $details = json_decode($row['details'], true) ?: [];

        return new SiemEvent(
            timestamp: new \DateTimeImmutable($row['created_at']),
            eventType: $eventType,
            severity: SiemSeverityMap::getSeverity($eventType),
            actorType: $row['actor_type'],
            actorId: $row['actor_id'],
            action: $row['action'],
            outcome: $row['outcome'],
            details: $details,
            resourceType: $row['resource_type'],
            resourceId: $row['resource_id'],
            ipAddress: $row['ip_address'],
            traceId: $row['trace_id'],
        );
    }

    /**
     * Parse a relative or absolute time string into a DateTimeImmutable.
     */
    public function parseSince(string $value): \DateTimeImmutable
    {
        // Relative: "24h", "7d", "30m"
        if (preg_match('/^(\d+)([hdm])$/', $value, $m)) {
            $amount = (int) $m[1];
            $unit = match ($m[2]) {
                'h' => 'hours',
                'd' => 'days',
                'm' => 'minutes',
            };

            return new \DateTimeImmutable("-{$amount} {$unit}");
        }

        // Absolute date
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($date !== false) {
            return $date->setTime(0, 0);
        }

        return new \DateTimeImmutable('-24 hours');
    }
}
