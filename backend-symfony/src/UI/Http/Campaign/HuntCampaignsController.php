<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Application\Campaign\CampaignHunter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/v1/campaign/hunt', name: 'api_campaign_hunt', methods: ['POST'])]
#[IsGranted('campaign:hunt')]
final readonly class HuntCampaignsController
{
    public function __construct(
        private CampaignHunter $hunter
    ) {
    }
    #[OA\Post(
        path: '/api/v1/campaign/hunt',
        summary: 'Exécuter le hunter sur toutes les règles actives',
        description: 'Exécute toutes les règles de campagne actives en mode shadow (lecture seule). Calcule PPV, lead-time et met à jour les métriques. Typiquement appelé via CRON toutes les heures.',
        tags: ['Campaign Radar'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chasse terminée avec succès',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'total_rules', type: 'integer', description: 'Nombre de règles exécutées'),
                        new OA\Property(property: 'total_hits', type: 'integer', description: 'Nombre total de détections'),
                        new OA\Property(
                            property: 'results',
                            type: 'array',
                            description: 'Résultats détaillés par règle',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'rule_id', type: 'string', format: 'uuid'),
                                    new OA\Property(property: 'status', type: 'string', enum: ['ok', 'error']),
                                    new OA\Property(property: 'hits_count', type: 'integer'),
                                    new OA\Property(property: 'ppv', type: 'number', format: 'float'),
                                    new OA\Property(property: 'lead_time_sec', type: 'integer', nullable: true),
                                    new OA\Property(property: 'latency_ms', type: 'integer'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Erreur lors de l\'exécution du hunter',
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
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $result = $this->hunter->hunt();

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Hunt execution failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
