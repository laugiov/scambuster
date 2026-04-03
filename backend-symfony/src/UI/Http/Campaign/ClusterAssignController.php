<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\ClusterAssignHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/cluster/assign', name: 'api_campaign_cluster_assign', methods: ['POST'])]
#[IsGranted('campaign:read')]
final class ClusterAssignController
{
    public function __construct(
        private readonly ClusterAssignHandler $handler
    ) {
    }

    #[OA\Post(
        path: '/api/v1/campaign/cluster/assign',
        summary: 'Assigner un message à une campagne via clustering',
        description: 'Analyse un message et l\'assigne à une campagne existante ou en crée une nouvelle via le service de clustering.',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                required: ['msg_id'],
                properties: [
                    new OA\Property(
                        property: 'msg_id',
                        type: 'string',
                        format: 'uuid',
                        description: 'UUID du message à assigner'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message assigné à une campagne existante',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'is_new_campaign', type: 'boolean'),
                        new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                    ]
                )
            ),
            new OA\Response(
                response: 201,
                description: 'Nouvelle campagne créée pour ce message',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'is_new_campaign', type: 'boolean'),
                        new OA\Property(property: 'confidence', type: 'number', format: 'float'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Paramètres invalides',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Message introuvable',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(Request $request): JsonResponse
    {
        // 1. Validation input
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['msg_id'])) {
            return new JsonResponse(['error' => 'msg_id is required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $messageId = Uuid::fromString($data['msg_id']);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid msg_id format'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Appel au handler
        try {
            $result = $this->handler->handle($messageId);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        // 3. Réponse
        return new JsonResponse([
            'campaign_id' => $result['campaign_id'],
            'is_new_campaign' => $result['is_new'],
            'confidence' => (float) number_format($result['confidence'], 4, '.', ''),
        ], $result['is_new'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
