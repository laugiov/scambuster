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

/**
 * Spec 065b — Admin endpoint to toggle the LLM kill switch at runtime
 * without restarting the backend.
 *
 * Persists the state in the application cache pool (PSR-6, Redis-backed
 * in production). Read at every reply generation by
 * `ReplyCadenceService::isKillSwitchActive()`.
 *
 * Layered with the env var fallback `SCAMBUSTER_KILL_SWITCH` for
 * emergency operator access (shell-only, no admin token).
 *
 * Audit event `KILL_SWITCH_TOGGLED` is emitted on every toggle (on or off).
 */
#[Route('/api/v1/admin/llm/killswitch')]
#[IsGranted('ROLE_ADMIN')]
final class AdminLlmKillSwitchController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/admin/llm/killswitch',
        summary: 'Get the current LLM kill switch state (Spec 065b)',
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current state',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'active', type: 'boolean'),
                    ],
                ),
            ),
            new OA\Response(response: 403, description: 'Forbidden — requires ROLE_ADMIN'),
        ],
        security: [['Bearer' => []]],
    )]
    #[Route(name: 'admin_llm_killswitch_get', methods: ['GET'])]
    public function getState(): JsonResponse
    {
        $item = $this->cache->getItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        $active = $item->isHit() && $item->get() === true;

        return new JsonResponse(['active' => $active]);
    }

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
    #[Route(name: 'admin_llm_killswitch_post', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
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
            // No TTL: persistent until explicitly cleared
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
