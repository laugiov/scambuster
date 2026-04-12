<?php

declare(strict_types=1);

namespace App\UI\Http\Admin;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\ReplyCadenceService;
use App\Domain\Audit\AuditEventType;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin/llm/killswitch', name: 'admin_llm_killswitch_post', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
#[OA\Post(
    path: '/api/v1/admin/llm/killswitch',
    summary: 'Toggle the LLM kill switch on or off (Spec 065b)',
    description: 'Persists the state in the application cache pool. Audit event KILL_SWITCH_TOGGLED is emitted.',
    tags: ['Admin'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['active'],
            properties: [
                new OA\Property(property: 'active', type: 'boolean', description: 'true to halt all replies, false to resume'),
            ],
        ),
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Toggle accepted',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'active', type: 'boolean'),
                ],
            ),
        ),
        new OA\Response(response: 400, description: 'Invalid body'),
        new OA\Response(response: 403, description: 'Forbidden — requires ROLE_ADMIN'),
    ],
    security: [['Bearer' => []]],
)]
final class ToggleLlmKillSwitchController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode((string) $request->getContent(), true);

        if (!is_array($data) || !array_key_exists('active', $data) || !is_bool($data['active'])) {
            return new JsonResponse(
                ['error' => 'Body must contain a boolean field "active"'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $active = $data['active'];

        if ($active) {
            $item = $this->cache->getItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
            $item->set(true);
            $this->cache->save($item);
        } else {
            $this->cache->deleteItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::KILL_SWITCH_TOGGLED,
            actorId: $request->headers->get('X-User-Id', 'admin'),
            action: 'toggle',
            outcome: $active ? 'activated' : 'deactivated',
            resourceType: 'llm_kill_switch',
            resourceId: 'global',
            details: ['active' => $active],
        );

        return new JsonResponse(['active' => $active]);
    }
}
