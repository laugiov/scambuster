<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use Doctrine\DBAL\Connection;

class AuditQueryService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{total: int, events: list<array<string, mixed>>}
     */
    public function query(?string $eventType, ?string $actorId, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if ($eventType !== null && $eventType !== '') {
            $where[] = 'event_type = :event_type';
            $params['event_type'] = $eventType;
        }

        if ($actorId !== null && $actorId !== '') {
            $where[] = 'actor_id = :actor_id';
            $params['actor_id'] = $actorId;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        /** @var int|string|false $totalRaw */
        $totalRaw = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log {$whereClause}",
            $params
        );
        $total = (int) $totalRaw;

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM audit_log {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            array_merge(
                array_fill_keys(array_keys($params), \PDO::PARAM_STR),
                ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]
            )
        );

        $events = array_map(function (array $row): array {
            /** @var int|string $rowId */
            $rowId = $row['id'] ?? 0;
            /** @var string $details */
            $details = $row['details'] ?? '{}';

            return [
                'id' => (int) $rowId,
                'event_type' => $row['event_type'],
                'actor_type' => $row['actor_type'],
                'actor_id' => $row['actor_id'],
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'action' => $row['action'],
                'outcome' => $row['outcome'],
                'details' => json_decode($details, true),
                'ip_address' => $row['ip_address'],
                'trace_id' => $row['trace_id'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return [
            'total' => $total,
            'events' => $events,
        ];
    }
}
