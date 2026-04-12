<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\AuditQueryService;
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
        private readonly AuditQueryService $auditQueryService,
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
        /** @var string|null $eventType */
        $eventType = $request->query->get('event_type');
        /** @var string|null $actorId */
        $actorId = $request->query->get('actor_id');
        $limit = min((int) ($request->query->get('limit', '50')), 200);
        $offset = max((int) ($request->query->get('offset', '0')), 0);

        $result = $this->auditQueryService->query($eventType, $actorId, $limit, $offset);

        return new JsonResponse([
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'events' => $result['events'],
        ]);
    }
}
