<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptOverrideHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/prompt-overrides/{key}', name: 'api_prompt_override_get', methods: ['GET'])]
#[IsGranted('config:write')]
final class GetPromptOverrideController extends AbstractController
{
    public function __construct(
        private readonly PromptOverrideHandler $handler,
    ) {
    }

    public function __invoke(string $key): JsonResponse
    {
        try {
            $data = $this->handler->get($key);
        } catch (UnknownPromptKeyException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'data' => $data,
        ]);
    }
}
