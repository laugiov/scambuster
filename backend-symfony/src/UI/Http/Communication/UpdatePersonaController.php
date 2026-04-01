<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\PersonaManager;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[OA\Put(
    path: '/api/v1/personas/{personaCode}',
    summary: 'Update persona label, tone, or system prompt',
    tags: ['Personas'],
    parameters: [
        new OA\Parameter(name: 'personaCode', in: 'path', required: true, schema: new OA\Schema(type: 'string', example: 'senior_trusting')),
    ],
    requestBody: new OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'persona_label', type: 'string', maxLength: 128),
                new OA\Property(property: 'persona_tone', type: 'string', maxLength: 256),
                new OA\Property(property: 'system_prompt', type: 'string', minLength: 100, maxLength: 5000),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Persona updated'),
        new OA\Response(response: 404, description: 'Persona not found'),
        new OA\Response(response: 422, description: 'Validation error'),
    ],
    security: [['Bearer' => []]]
)]
#[Route('/api/v1/personas/{personaCode}', name: 'api_persona_update', methods: ['PUT'])]
final class UpdatePersonaController extends AbstractController
{
    private const MAX_LABEL_LENGTH = 128;
    private const MAX_TONE_LENGTH = 256;
    private const MIN_PROMPT_LENGTH = 100;
    private const MAX_PROMPT_LENGTH = 5000;

    public function __construct(
        private readonly PersonaManager $personaManager
    ) {
    }

    public function __invoke(Request $request, string $personaCode): JsonResponse
    {
        // 1. Find persona
        $persona = $this->personaManager->findByCode($personaCode);

        if ($persona === null) {
            return new JsonResponse([
                'success' => false,
                'error' => "Persona '{$personaCode}' not found",
            ], Response::HTTP_NOT_FOUND);
        }

        // 2. Parse and validate JSON body
        $body = json_decode($request->getContent(), true);

        if (!is_array($body)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid JSON body',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3. Whitelist allowed fields — reject unknown keys (mass assignment protection)
        $allowedFields = ['persona_label', 'persona_tone', 'system_prompt'];
        $unknownFields = array_diff(array_keys($body), $allowedFields);

        if (!empty($unknownFields)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Unknown fields: ' . implode(', ', $unknownFields),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4. At least one field must be provided
        $label = isset($body['persona_label']) ? $this->sanitize($body['persona_label']) : null;
        $tone = isset($body['persona_tone']) ? $this->sanitize($body['persona_tone']) : null;
        $prompt = isset($body['system_prompt']) ? $this->sanitize($body['system_prompt']) : null;

        if ($label === null && $tone === null && $prompt === null) {
            return new JsonResponse([
                'success' => false,
                'error' => 'At least one field must be provided: persona_label, persona_tone, system_prompt',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 5. Validate each field
        $errors = [];

        if ($label !== null) {
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                $errors[] = 'persona_label must be 1-' . self::MAX_LABEL_LENGTH . ' characters';
            }
        }

        if ($tone !== null) {
            if ($tone === '' || mb_strlen($tone) > self::MAX_TONE_LENGTH) {
                $errors[] = 'persona_tone must be 1-' . self::MAX_TONE_LENGTH . ' characters';
            }
        }

        if ($prompt !== null) {
            if (mb_strlen($prompt) < self::MIN_PROMPT_LENGTH) {
                $errors[] = 'system_prompt must be at least ' . self::MIN_PROMPT_LENGTH . ' characters';
            }

            if (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
                $errors[] = 'system_prompt must be at most ' . self::MAX_PROMPT_LENGTH . ' characters';
            }
        }

        if (!empty($errors)) {
            return new JsonResponse([
                'success' => false,
                'error' => implode('; ', $errors),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 6. Update via PersonaManager (uses Doctrine parameterized queries — SQL injection safe)
        try {
            $this->personaManager->updatePersona($persona, $label, $tone, $prompt);
        } catch (\RuntimeException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 7. Return updated persona
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
        ]);
    }

    /**
     * Sanitize input: strip control characters and normalize whitespace.
     * Prevents stored XSS via control chars and null bytes.
     */
    private function sanitize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        // Remove null bytes and control characters (except newline, tab)
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // Normalize line endings
        $value = str_replace("\r\n", "\n", $value ?? '');

        return trim($value);
    }
}
