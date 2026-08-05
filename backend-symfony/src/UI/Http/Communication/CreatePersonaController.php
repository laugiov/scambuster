<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Communication\PersonaManager;
use App\Domain\Audit\AuditEventType;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Create a persona from the admin UI.
 *
 * Lets operators add personas tuned to their own organization's activity without
 * touching fixtures/code. Scam-type links are optional: the bandit selects from all
 * active personas, so a new active persona is eligible immediately (cold start).
 */
#[OA\Post(
    path: '/api/v1/personas',
    summary: 'Create a persona',
    tags: ['Personas'],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(
            required: ['persona_code', 'persona_label', 'persona_tone', 'system_prompt'],
            properties: [
                new OA\Property(property: 'persona_code', type: 'string', example: 'logistics_dispatcher'),
                new OA\Property(property: 'persona_label', type: 'string', maxLength: 128),
                new OA\Property(property: 'persona_tone', type: 'string', maxLength: 256),
                new OA\Property(property: 'system_prompt', type: 'string', minLength: 100, maxLength: 5000),
                new OA\Property(property: 'scam_type_codes', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 201, description: 'Persona created'),
        new OA\Response(response: 409, description: 'Persona code already exists'),
        new OA\Response(response: 422, description: 'Validation error'),
        new OA\Response(response: 400, description: 'Unknown scam type'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/personas', name: 'api_persona_create', methods: ['POST'])]
#[IsGranted('config:write')]
final class CreatePersonaController extends AbstractController
{
    private const MAX_LABEL_LENGTH = 128;
    private const MAX_TONE_LENGTH = 256;
    private const MIN_PROMPT_LENGTH = 100;
    private const MAX_PROMPT_LENGTH = 5000;
    private const ALLOWED_FIELDS = [
        'persona_code', 'persona_label', 'persona_tone', 'system_prompt', 'scam_type_codes',
    ];

    public function __construct(
        private readonly PersonaManager $personaManager,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return $this->error('Invalid JSON body', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $unknownFields = array_diff(array_keys($body), self::ALLOWED_FIELDS);

        if ($unknownFields !== []) {
            return $this->error('Unknown fields: ' . implode(', ', $unknownFields), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $code = $this->sanitize($body['persona_code'] ?? null);
        $label = $this->sanitize($body['persona_label'] ?? null);
        $tone = $this->sanitize($body['persona_tone'] ?? null);
        $prompt = $this->sanitize($body['system_prompt'] ?? null);

        // Validation
        $errors = [];

        if ($code === null || !preg_match('/^[a-z_]{3,30}$/', $code)) {
            $errors[] = 'persona_code must be snake_case, 3-30 characters';
        }

        if ($label === null || $label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            $errors[] = 'persona_label must be 1-' . self::MAX_LABEL_LENGTH . ' characters';
        }

        if ($tone === null || $tone === '' || mb_strlen($tone) > self::MAX_TONE_LENGTH) {
            $errors[] = 'persona_tone must be 1-' . self::MAX_TONE_LENGTH . ' characters';
        }

        if ($prompt === null || mb_strlen($prompt) < self::MIN_PROMPT_LENGTH || mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
            $errors[] = 'system_prompt must be ' . self::MIN_PROMPT_LENGTH . '-' . self::MAX_PROMPT_LENGTH . ' characters';
        }

        $scamTypeCodes = [];

        if (isset($body['scam_type_codes'])) {
            if (!is_array($body['scam_type_codes'])) {
                $errors[] = 'scam_type_codes must be an array of strings';
            } else {
                foreach ($body['scam_type_codes'] as $c) {
                    if (is_string($c) && $c !== '') {
                        $scamTypeCodes[] = $c;
                    }
                }
            }
        }

        if ($errors !== []) {
            return $this->error(implode('; ', $errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Create
        try {
            $persona = $this->personaManager->createPersona(
                (string) $code,
                (string) $label,
                (string) $tone,
                (string) $prompt,
                'operator',
                $scamTypeCodes,
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'already exists')) {
                return $this->error($message, Response::HTTP_CONFLICT);
            }

            if (str_contains($message, 'Unknown scam_type')) {
                return $this->error($message, Response::HTTP_BAD_REQUEST);
            }

            return $this->error($message, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::CONFIG_CHANGED,
            actorId: $this->getUser()?->getUserIdentifier() ?? 'unknown',
            action: 'persona.create',
            outcome: 'success',
            resourceType: 'persona',
            resourceId: $persona->getPersonaCode(),
            details: ['scam_type_codes' => $scamTypeCodes],
        );

        return new JsonResponse([
            'success' => true,
            'data' => [
                'persona_code' => $persona->getPersonaCode(),
                'persona_label' => $persona->getPersonaLabel(),
                'persona_tone' => $persona->getPersonaTone(),
                'system_prompt' => $persona->getSystemPrompt(),
                'is_active' => $persona->isActive(),
                'created_by' => $persona->getCreatedBy(),
                'created_at' => $persona->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'error' => $message], $status);
    }

    private function sanitize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = str_replace("\r\n", "\n", $value ?? '');

        return trim($value);
    }
}
