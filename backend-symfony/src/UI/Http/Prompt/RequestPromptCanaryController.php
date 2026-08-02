<?php

declare(strict_types=1);

namespace App\UI\Http\Prompt;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Guard\CanaryAvailability;
use App\Application\Prompt\Exception\InvalidPromptOverrideException;
use App\Application\Prompt\Exception\UnknownPromptKeyException;
use App\Application\Prompt\PromptCanaryJobHandler;
use App\Domain\Audit\AuditEventType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Enqueue an async canary validation for an UNSAVED candidate prompt body. Returns 202 with the
 * job id; the dedicated worker runs the real-LLM smoke and stores the verdict, which the UI polls
 * via {@see GetPromptCanaryController}. It never activates or mutates the operator's real override.
 */
#[Route('/api/v1/prompt-overrides/{key}/canary', name: 'api_prompt_override_canary_request', methods: ['POST'])]
#[IsGranted('config:write')]
final class RequestPromptCanaryController extends AbstractController
{
    private const ALLOWED_FIELDS = ['body'];

    public function __construct(
        private readonly PromptCanaryJobHandler $handler,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly CanaryAvailability $availability,
    ) {
    }

    public function __invoke(Request $request, string $key): JsonResponse
    {
        // Reject up-front when this deployment has no live model provider: the canary drives the
        // real reply pipeline, so without usable credentials an enqueued job could only hang. The
        // admin UI hides "Validate" for the same reason; this is the server-side backstop for a
        // direct API call that bypasses the UI.
        if (!$this->availability->isConfigured()) {
            return $this->error(
                'Prompt validation is unavailable on this deployment (no live model provider configured).',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

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

        try {
            $jobId = $this->handler->request($key, $body['body'], $this->getUser()?->getUserIdentifier());
        } catch (UnknownPromptKeyException $e) {
            return $this->error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (InvalidPromptOverrideException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::CONFIG_CHANGED,
            actorId: $this->getUser()?->getUserIdentifier() ?? 'unknown',
            action: 'prompt_override.canary.request',
            outcome: 'success',
            resourceType: 'prompt_override',
            resourceId: $key,
            details: ['job_id' => $jobId],
        );

        return new JsonResponse(
            ['success' => true, 'data' => ['job_id' => $jobId, 'status' => 'pending']],
            Response::HTTP_ACCEPTED,
        );
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }
}
