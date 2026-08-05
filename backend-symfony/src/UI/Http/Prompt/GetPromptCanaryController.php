<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Prompt\Exception\CanaryJobNotFoundException;
use App\Application\Prompt\PromptCanaryJobHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Poll a canary validation job: its status and, once finished, the verdict (or the error). The
 * jobId requirement is numeric so this route never shadows GET /prompt-overrides/{key}, and is
 * bounded to 18 digits so an over-range id never overflows the int coercion (which would 500) —
 * a too-large id simply does not match the route and returns a clean 404.
 */
#[Route('/api/v1/prompt-overrides/canary/{jobId}', name: 'api_prompt_override_canary_get', methods: ['GET'], requirements: ['jobId' => '\d{1,18}'])]
#[IsGranted('config:write')]
final class GetPromptCanaryController extends AbstractController
{
    public function __construct(
        private readonly PromptCanaryJobHandler $handler,
    ) {
    }

    public function __invoke(int $jobId): JsonResponse
    {
        try {
            $data = $this->handler->view($jobId);
        } catch (CanaryJobNotFoundException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['success' => true, 'data' => $data]);
    }
}
