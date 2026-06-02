<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Communication\ScamClassificationHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/conversation/{convId}/auto-classify',
    summary: 'Auto-classify a conversation using LLM',
    tags: ['Conversations', 'Classification'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'force', type: 'boolean', description: 'Force reclassification even if already classified', default: false),
                new OA\Property(property: 'confidence_threshold', type: 'number', description: 'Minimum confidence threshold (0.0-1.0). Default lowered to 0.55 by spec 095 Fix #2 to accept hybrid scams.', default: 0.55, minimum: 0, maximum: 1),
            ]
        )
    ),
    responses: [
        new OA\Response(
            response: 200,
            description: 'Conversation classified successfully',
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'conv_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'scam_type_code', type: 'string'),
                    new OA\Property(property: 'scam_type_label', type: 'string'),
                    new OA\Property(property: 'persona_code', type: 'string', nullable: true),
                    new OA\Property(property: 'persona_label', type: 'string', nullable: true),
                    new OA\Property(property: 'confidence', type: 'number', description: 'Classification confidence (0.0-1.0)'),
                    new OA\Property(property: 'classified_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'is_new_scam_type', type: 'boolean', description: 'True if a new scam type was created'),
                    new OA\Property(property: 'is_new_persona', type: 'boolean', description: 'True if a new persona was created'),
                    new OA\Property(
                        property: 'secondary_types',
                        type: 'array',
                        description: 'Secondary scam type classifications (multi-label)',
                        items: new OA\Items(
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'code', type: 'string'),
                                new OA\Property(property: 'confidence', type: 'number'),
                            ]
                        ),
                        nullable: true
                    ),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 400,
            description: 'Classification failed',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class AutoClassifyConversationController
{
    public function __construct(
        private ScamClassificationHandler $classificationHandler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}/auto-classify', name: 'auto_classify_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if ($data !== null && !is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $force = ($data['force'] ?? false) === true;
        // Spec 095 Fix #2 — default lowered 0.75 → 0.55 (Phase 0 audit fix).
        $confidenceThreshold = $data['confidence_threshold'] ?? 0.55;

        try {
            $result = $this->classificationHandler->autoClassifyConversation(
                $convId,
                $force,
                $confidenceThreshold
            );

            return new JsonResponse([
                'conv_id' => $convId,
                'scam_type_code' => $result['scam_type_code'],
                'scam_type_label' => $result['scam_type_label'],
                'persona_code' => $result['persona_code'] ?? null,
                'persona_label' => $result['persona_label'] ?? null,
                'confidence' => $result['confidence'],
                'classified_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'is_new_scam_type' => $result['is_new_scam_type'],
                'is_new_persona' => $result['is_new_persona'],
                'secondary_types' => $result['secondary_types'] ?? null,
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['error' => 'Auto-classification failed'], Response::HTTP_BAD_REQUEST);
        }
    }
}
