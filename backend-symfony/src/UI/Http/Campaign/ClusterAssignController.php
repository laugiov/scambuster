<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\ClusterAssignHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/cluster/assign', name: 'api_campaign_cluster_assign', methods: ['POST'])]
final class ClusterAssignController
{
    public function __construct(
        private readonly ClusterAssignHandler $handler
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // 1. Validation input
        $data = json_decode($request->getContent(), true);

        if (!isset($data['msg_id'])) {
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
