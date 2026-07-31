<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptOverrideHandler;
use App\Domain\Audit\AuditEventType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/prompt-overrides/{key}', name: 'api_prompt_override_upsert', methods: ['PUT'])]
#[IsGranted('config:write')]
final class UpsertPromptOverrideController extends AbstractController
{
    private const ALLOWED_FIELDS = ['body', 'enabled'];

    public function __construct(
        private readonly PromptOverrideHandler $handler,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Request $request, string $key): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->error('Invalid JSON body', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $unknown = array_diff(array_keys($body), self::ALLOWED_FIELDS);

        if ($unknown !== []) {
            return $this->error('Unknown fields: ' . implode(', ', $unknown), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!isset($body['body']) || !is_string($body['body'])) {
            return $this->error('Field "body" (string) is required', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $enabled = ($body['enabled'] ?? true) === true;

        try {
            $this->handler->upsert($key, $body['body'], $enabled, $this->getUser()?->getUserIdentifier());
        } catch (UnknownPromptKeyException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidPromptOverrideException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::CONFIG_CHANGED,
            actorId: $this->getUser()?->getUserIdentifier() ?? 'unknown',
            action: 'prompt_override.upsert',
            outcome: 'success',
            resourceType: 'prompt_override',
            resourceId: $key,
            details: ['enabled' => $enabled],
        );

        return new JsonResponse([
            'success' => true,
            'data' => $this->handler->get($key),
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
