<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptOverrideHandler;
use App\Domain\Audit\AuditEventType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/prompt-overrides/{key}', name: 'api_prompt_override_delete', methods: ['DELETE'])]
#[IsGranted('config:write')]
final class DeletePromptOverrideController extends AbstractController
{
    public function __construct(
        private readonly PromptOverrideHandler $handler,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(string $key): JsonResponse
    {
        // Unknown key is a client error; a known key with no stored override is a no-op.
        try {
            $this->handler->get($key);
        } catch (UnknownPromptKeyException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $removed = $this->handler->delete($key);

        if ($removed) {
            $this->auditLogger->log(
                eventType: AuditEventType::CONFIG_CHANGED,
                actorId: $this->getUser()?->getUserIdentifier() ?? 'unknown',
                action: 'prompt_override.delete',
                outcome: 'success',
                resourceType: 'prompt_override',
                resourceId: $key,
            );
        }

        return new JsonResponse([
            'success' => true,
            'data' => ['removed' => $removed],
        ]);
    }
}
