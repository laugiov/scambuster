<?php

declare(strict_types=1);

namespace App\UI\Http\Admin;

use App\Application\Communication\ReplyCadenceService;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/admin/llm/killswitch', name: 'admin_llm_killswitch_get', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
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
final class GetLlmKillSwitchStateController
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $item = $this->cache->getItem(ReplyCadenceService::KILL_SWITCH_CACHE_KEY);
        $active = $item->isHit() && $item->get() === true;

        return new JsonResponse(['active' => $active]);
    }
}
