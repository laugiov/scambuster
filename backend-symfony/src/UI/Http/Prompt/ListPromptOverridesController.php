<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Prompt\PromptOverrideHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/prompt-overrides', name: 'api_prompt_override_list', methods: ['GET'])]
#[IsGranted('config:write')]
final class ListPromptOverridesController extends AbstractController
{
    public function __construct(
        private readonly PromptOverrideHandler $handler,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $this->handler->list(),
        ]);
    }
}
