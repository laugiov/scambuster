<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Audit log query endpoint.
 *
 * Returns structured audit events, paginated and filterable.
 * Auth handled by Symfony firewall (access_control: ROLE_ADMIN for /monitoring).
 */
final class AuditController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('/api/v1/monitoring/audit', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $eventType = $request->query->get('event_type');
        $actorId = $request->query->get('actor_id');
        $limit = min((int) ($request->query->get('limit', '50')), 200);
        $offset = max((int) ($request->query->get('offset', '0')), 0);

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

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log {$whereClause}",
            $params
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM audit_log {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
            array_merge($params, ['limit' => $limit, 'offset' => $offset]),
            array_merge(
                array_fill_keys(array_keys($params), \PDO::PARAM_STR),
                ['limit' => \PDO::PARAM_INT, 'offset' => \PDO::PARAM_INT]
            )
        );

        $events = array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'event_type' => $row['event_type'],
                'actor_type' => $row['actor_type'],
                'actor_id' => $row['actor_id'],
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
                'action' => $row['action'],
                'outcome' => $row['outcome'],
                'details' => json_decode((string) ($row['details'] ?? '{}'), true),
                'ip_address' => $row['ip_address'],
                'trace_id' => $row['trace_id'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        return new JsonResponse([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'events' => $events,
        ]);
    }
}
