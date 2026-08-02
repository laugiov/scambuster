<?php

declare(strict_types=1);

namespace App\UI\Http\ThreatActor;

use App\Application\Audit\AuditLogger;
use App\Application\ThreatActor\IocFeedbackService;
use App\Domain\Audit\AuditEventType;
use App\Domain\ThreatActor\AnalystVerdict;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/iocs/{indicatorId}/feedback',
    summary: 'Submit an analyst verdict (confirmed / false-positive) on an IOC',
    tags: ['IOCs'],
    parameters: [new OA\Parameter(name: 'indicatorId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Verdict recorded'),
        new OA\Response(response: 404, description: 'Indicator not found'),
        new OA\Response(response: 422, description: 'Invalid verdict'),
    ],
    security: [['Bearer' => []]],
)]
#[Route('/api/v1/iocs/{indicatorId}/feedback', name: 'ioc_feedback_submit', methods: ['POST'], requirements: ['indicatorId' => '[0-9a-f-]{36}'])]
#[IsGranted('ioc:feedback')]
final class SubmitIocFeedbackController extends AbstractController
{
    public function __construct(
        private readonly IocFeedbackService $feedback,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(string $indicatorId, Request $request): JsonResponse
    {
        if (!$this->feedback->indicatorExists($indicatorId)) {
            return new JsonResponse(['error' => 'Indicator not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $verdict = \is_string($data['verdict'] ?? null) ? AnalystVerdict::tryFrom($data['verdict']) : null;

        if (!$verdict instanceof AnalystVerdict) {
            return new JsonResponse(
                ['error' => 'verdict must be one of: ' . implode(', ', AnalystVerdict::values())],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $note = \is_string($data['note'] ?? null) && $data['note'] !== '' ? mb_substr($data['note'], 0, 1000) : null;
        $analystId = $this->getUser()?->getUserIdentifier() ?? 'unknown';

        $this->feedback->submit($indicatorId, $verdict, $note, $analystId);

        $this->auditLogger->log(
            eventType: AuditEventType::IOC_FEEDBACK,
            actorId: $analystId,
            action: 'ioc_feedback',
            outcome: 'success',
            resourceType: 'indicator',
            resourceId: $indicatorId,
            details: ['verdict' => $verdict->value],
            ipAddress: $request->getClientIp(),
        );

        return new JsonResponse(['indicator_id' => $indicatorId, 'verdict' => $verdict->value], Response::HTTP_OK);
    }
}
