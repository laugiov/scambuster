<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignStixExportHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/campaign/{campaignId}/export/stix', name: 'api_campaign_export_stix', methods: ['POST'])]
#[IsGranted('campaign:read')]
final readonly class ExportCampaignSTIXController
{
    public function __construct(
        private CampaignStixExportHandler $handler,
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/{campaignId}/export/stix',
        summary: 'Export a campaign in STIX 2.1 format',
        description: 'Generates a STIX 2.1 bundle for a given campaign by extracting IoCs from the YAML profile. The JSON file is saved to disk.',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        parameters: [
            new OA\Parameter(
                name: 'campaignId',
                in: 'path',
                required: true,
                description: 'UUID of the campaign to export',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'STIX export completed successfully',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'STIX export completed'),
                        new OA\Property(property: 'file_path', type: 'string', description: 'Path of the generated STIX file'),
                        new OA\Property(property: 'bundle_id', type: 'string', description: 'STIX bundle identifier (bundle--uuid)'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid campaign_id format',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campaign not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'STIX export error',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(string $campaignId): JsonResponse
    {
        try {
            $data = $this->handler->export($campaignId);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid campaign_id format'], Response::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Campaign not found') {
                return new JsonResponse(['error' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
            }

            return new JsonResponse([
                'error' => 'STIX export failed',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($data);
    }
}
