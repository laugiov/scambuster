<?php

declare(strict_types=1);

namespace App\UI\Http\Communication;

use App\Application\Stix\ConversationStixExportHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Export conversation IOCs as a STIX 2.1 bundle compatible with OpenCTI import.
 */
#[IsGranted('ioc:export')]
final class ExportConversationStixController
{
    public function __construct(
        private readonly ConversationStixExportHandler $handler,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/conversations/{conv_id}/export/stix',
        summary: 'Export conversation IOCs as STIX 2.1 bundle (OpenCTI compatible)',
        tags: ['Export'],
        parameters: [
            new OA\Parameter(
                name: 'conv_id',
                in: 'path',
                required: true,
                description: 'Conversation UUID',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'STIX 2.1 bundle JSON'),
            new OA\Response(response: 404, description: 'Conversation not found'),
        ],
        security: [['Bearer' => []]]
    )]
    #[Route('/api/v1/conversations/{conv_id}/export/stix', name: 'export_conversation_stix', methods: ['GET'])]
    public function __invoke(string $conv_id, Request $request): JsonResponse
    {
        $includeThreatActor = $request->query->get('include_threat_actor', 'true') !== 'false';

        try {
            $bundle = $this->handler->export($conv_id, $includeThreatActor);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Conversation not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($bundle, Response::HTTP_OK);
    }
}
