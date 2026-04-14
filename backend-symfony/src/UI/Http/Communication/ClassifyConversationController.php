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
    path: '/api/v1/communication/conversation/{convId}/classify',
    summary: 'Classify a conversation manually with a specific scam type and persona',
    tags: ['Conversations', 'Classification'],
    parameters: [
        new OA\Parameter(name: 'convId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['scam_type_code'],
            properties: [
                new OA\Property(property: 'scam_type_code', type: 'string', description: 'Scam type code (e.g., PHISHING, INVOICE_FRAUD)', example: 'PHISHING'),
                new OA\Property(property: 'persona_code', type: 'string', description: 'Persona code to assign (optional)', example: 'tech_savvy_user'),
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
                    new OA\Property(property: 'classified_at', type: 'string', format: 'date-time'),
                ]
            )
        ),
        new OA\Response(
            response: 404,
            description: 'Conversation or scam type not found',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
        new OA\Response(
            response: 400,
            description: 'Validation error',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        ),
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final readonly class ClassifyConversationController
{
    public function __construct(
        private ScamClassificationHandler $classificationHandler
    ) {
    }
    #[Route('/api/v1/communication/conversation/{convId}/classify', name: 'classify_conversation', methods: ['POST'])]
    #[IsGranted('conversation:write')]
    public function __invoke(string $convId, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['scam_type_code'])) {
            return new JsonResponse(['error' => 'scam_type_code is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->classificationHandler->manualClassifyConversation(
                $convId,
                $data['scam_type_code'],
                $data['persona_code'] ?? null
            );

            return new JsonResponse([
                'conv_id' => $convId,
                'scam_type_code' => $result['scam_type_code'],
                'scam_type_label' => $result['scam_type_label'],
                'persona_code' => $result['persona_code'] ?? null,
                'persona_label' => $result['persona_label'] ?? null,
                'classified_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], Response::HTTP_OK);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
