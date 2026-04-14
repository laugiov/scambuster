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
final readonly class ClusterAssignController
{
    public function __construct(
        private ClusterAssignHandler $handler
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/cluster/assign',
        summary: 'Assign a message to a campaign via clustering',
        description: 'Analyzes a message and assigns it to an existing campaign or creates a new one via the clustering service.',
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
                        description: 'UUID of the message to assign'
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Message assigned to an existing campaign',
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
                description: 'New campaign created for this message',
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
                description: 'Invalid parameters',
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
            /** @var string $msgIdStr */
            $msgIdStr = $data['msg_id'];
            $messageId = Uuid::fromString($msgIdStr);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid msg_id format'], Response::HTTP_BAD_REQUEST);
        }

        // 2. Appel au handler
        try {
            $result = $this->handler->handle($messageId);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        // 3. Response
        return new JsonResponse([
            'campaign_id' => $result['campaign_id'],
            'is_new_campaign' => $result['is_new'],
            'confidence' => (float) number_format($result['confidence'], 4, '.', ''),
        ], $result['is_new'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
