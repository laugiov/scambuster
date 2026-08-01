<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Prompt\PromptCanaryJobHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Return the most recent canary job for a prompt key (or null), so the admin UI can re-attach to a
 * running/recent validation after a reload — the client-side job handle is otherwise lost, dropping
 * the in-progress run or the fresh verdict on refresh/navigation. The key requirement (lowercase
 * word) keeps this 4-segment route from ever shadowing the numeric GET /prompt-overrides/canary/{jobId}.
 */
#[Route('/api/v1/prompt-overrides/{key}/canary/latest', name: 'api_prompt_override_canary_latest', methods: ['GET'], requirements: ['key' => '[a-z0-9_]+'])]
#[IsGranted('config:write')]
final class GetLatestPromptCanaryController extends AbstractController
{
    public function __construct(
        private readonly PromptCanaryJobHandler $handler,
    ) {
    }

    public function __invoke(string $key): JsonResponse
    {
        return new JsonResponse(['success' => true, 'data' => $this->handler->latestForKey($key)]);
    }
}
