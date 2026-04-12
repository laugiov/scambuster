<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Post(
    path: '/api/v1/communication/reply/draft',
    summary: 'Persister un brouillon (idempotent)',
    tags: ['Reply'],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            type: 'object',
            required: ['msg_id', 'draft'],
            properties: [
                new OA\Property(property: 'msg_id', type: 'string', format: 'uuid'),
                new OA\Property(
                    property: 'draft',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'text', type: 'string'),
                        new OA\Property(property: 'html', type: 'string'),
                    ]
                ),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 204, description: 'Brouillon sauvegardé'),
        new OA\Response(
            response: 404,
            description: 'Message non trouvé',
            content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'error', type: 'string')])
        )
    ],
    security: [ [ 'Bearer' => [] ] ]
)]
final class SaveDraftController
{
    #[Route('/api/v1/communication/reply/draft', name: 'save_reply_draft', methods: ['POST'])]
    #[IsGranted('reply:generate')]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['msg_id']) || empty($data['draft'])) {
            return new JsonResponse(['error' => 'Missing required fields: msg_id, draft'], Response::HTTP_BAD_REQUEST);
        }

        // For now, this is a no-op since draft is already saved in generate
        // In a real implementation, this could update an existing draft
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
