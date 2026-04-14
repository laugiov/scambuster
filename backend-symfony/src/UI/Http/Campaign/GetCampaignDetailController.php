<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignDetailHandler;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/campaign/{campaign_id}/detail', name: 'api_campaign_detail', methods: ['GET'])]
#[IsGranted('campaign:read')]
final readonly class GetCampaignDetailController
{
    public function __construct(
        private CampaignDetailHandler $handler,
    ) {
    }
    #[OA\Get(
        path: '/api/v1/campaign/{campaign_id}/detail',
        summary: 'Get campaign detail',
        description: 'Returns campaign metadata along with the best associated rule (sorted by PPV descending).',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        parameters: [
            new OA\Parameter(
                name: 'campaign_id',
                in: 'path',
                required: true,
                description: 'UUID de la campagne',
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Campaign detail',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'severity', type: 'string', nullable: true),
                        new OA\Property(property: 'tlp', type: 'string'),
                        new OA\Property(property: 'first_seen', type: 'string', format: 'date-time'),
                        new OA\Property(property: 'profile_yaml', type: 'string', nullable: true),
                        new OA\Property(property: 'notes', type: 'string', nullable: true),
                        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                        new OA\Property(
                            property: 'rule',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'ppv', type: 'number', format: 'float'),
                                new OA\Property(property: 'hits_total', type: 'integer'),
                                new OA\Property(property: 'hits_true_pos', type: 'integer'),
                                new OA\Property(property: 'hits_false_pos', type: 'integer'),
                                new OA\Property(property: 'lead_time_sec', type: 'integer', nullable: true),
                                new OA\Property(property: 'lead_time_hours', type: 'number', format: 'float', nullable: true),
                                new OA\Property(property: 'enabled', type: 'boolean'),
                                new OA\Property(property: 'promoted_at', type: 'string', format: 'date-time', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Campagne introuvable',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [new OA\Property(property: 'error', type: 'string')]
                )
            ),
        ]
    )]
    public function __invoke(string $campaign_id): JsonResponse
    {
        try {
            $data = $this->handler->getDetail($campaign_id);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($data);
    }
}
