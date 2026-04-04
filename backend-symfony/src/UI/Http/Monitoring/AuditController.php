<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit log query endpoint.
 *
 * Returns structured audit events, paginated and filterable.
 * Auth handled by Symfony firewall (access_control: ROLE_ADMIN for /monitoring).
 */
#[IsGranted('audit:read')]
final class AuditController
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    #[Route('/api/v1/monitoring/audit', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/monitoring/audit',
        summary: 'Query audit log events',
        tags: ['Monitoring'],
        parameters: [
            new OA\Parameter(name: 'event_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'actor_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 200)),
            new OA\Parameter(name: 'offset', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 0)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated audit events',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 150),
                        new OA\Property(property: 'limit', type: 'integer', example: 50),
                        new OA\Property(property: 'offset', type: 'integer', example: 0),
                        new OA\Property(
                            property: 'events',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'event_type', type: 'string'),
                                    new OA\Property(property: 'actor_type', type: 'string'),
                                    new OA\Property(property: 'actor_id', type: 'string'),
                                    new OA\Property(property: 'resource_type', type: 'string'),
                                    new OA\Property(property: 'resource_id', type: 'string'),
                                    new OA\Property(property: 'action', type: 'string'),
                                    new OA\Property(property: 'outcome', type: 'string'),
                                    new OA\Property(property: 'details', type: 'object'),
                                    new OA\Property(property: 'ip_address', type: 'string'),
                                    new OA\Property(property: 'trace_id', type: 'string'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ]
                            )
                        ),
                    ]
                )
            )
        ],
        security: [['Bearer' => []]]
    )]
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

        return new JsonResponse([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'events' => $events,
        ]);
    }
}
