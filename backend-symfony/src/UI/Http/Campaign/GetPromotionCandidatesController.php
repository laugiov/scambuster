<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignPromoter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/campaign/candidates', name: 'api_campaign_promotion_candidates', methods: ['GET'])]
#[IsGranted('campaign:read')]
final class GetPromotionCandidatesController
{
    public function __construct(
        private readonly CampaignPromoter $promoter
    ) {
    }

    #[OA\Get(
        path: '/api/v1/campaign/candidates',
        summary: 'Lister les candidats à la promotion',
        description: 'Évalue et retourne les règles de campagne éligibles à la promotion ainsi que les règles déjà promues (20 dernières).',
        security: [['Bearer' => []]],
        tags: ['Campaign'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Liste des candidats et règles promues',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(
                            property: 'candidates',
                            type: 'array',
                            description: 'Règles éligibles à la promotion',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'campaign_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'ppv', type: 'number', format: 'float'),
                                    new OA\Property(property: 'hits_total', type: 'integer'),
                                    new OA\Property(property: 'lead_time_sec', type: 'integer', nullable: true),
                                    new OA\Property(property: 'lead_time_hours', type: 'number', format: 'float', nullable: true),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
                                ]
                            )
                        ),
                        new OA\Property(
                            property: 'thresholds',
                            type: 'object',
                            description: 'Seuils de promotion configurés',
                            properties: [
                                new OA\Property(property: 'ppv_threshold', type: 'number', format: 'float'),
                                new OA\Property(property: 'min_hits', type: 'integer'),
                                new OA\Property(property: 'min_lead_time_sec', type: 'integer'),
                            ]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function __invoke(): JsonResponse
    {
        $result = $this->promoter->evaluateCandidates();

        return new JsonResponse([
            'candidates' => $result['candidates'],
            'thresholds' => $this->promoter->getThresholds(),
        ]);
    }
}
