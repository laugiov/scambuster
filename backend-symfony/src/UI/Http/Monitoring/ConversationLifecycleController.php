<?php

declare(strict_types=1);

namespace App\UI\Http\Monitoring;

use App\Application\Monitoring\ConversationLifecycleHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Conversation lifecycle monitoring endpoint.
 *
 * Returns active, about-to-timeout, completed today, by scam type.
 */
final class ConversationLifecycleController
{
    public function __construct(
        private readonly ConversationLifecycleHandler $handler
    ) {
    }

    #[Route('/api/v1/monitoring/conversation-lifecycle', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->handler->getLifecycleStats());
    }
}
