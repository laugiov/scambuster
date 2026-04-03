<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/campaign/{campaign_id}/detail', name: 'api_campaign_detail', methods: ['GET'])]
#[IsGranted('campaign:read')]
final class GetCampaignDetailController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[OA\Get(
        path: '/api/v1/campaign/{campaign_id}/detail',
        summary: 'Obtenir le détail d\'une campagne',
        description: 'Retourne les métadonnées d\'une campagne ainsi que la meilleure règle associée (triée par PPV décroissant).',
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
                description: 'Détail de la campagne',
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
        $campaign = $this->em->getRepository(Campaign::class)->find(Uuid::fromString($campaign_id));

        if (!$campaign) {
            return new JsonResponse(['error' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        $rules = $this->em->getRepository(CampaignRule::class)->findBy(
            ['campaignId' => Uuid::fromString($campaign_id)],
            ['ppv' => 'DESC']
        );

        $bestRule = $rules[0] ?? null;

        return new JsonResponse([
            'campaign_id' => $campaign->getCampaignId(),
            'status' => $campaign->getStatus()->value,
            'severity' => $campaign->getSeverity(),
            'tlp' => $campaign->getTlp(),
            'first_seen' => $campaign->getFirstSeen()->format(\DATE_ATOM),
            'profile_yaml' => $campaign->getProfileYaml(),
            'notes' => $campaign->getNotes(),
            'created_at' => $campaign->getCreatedAt()->format(\DATE_ATOM),
            'rule' => $bestRule ? [
                'rule_id' => $bestRule->getRuleId(),
                'ppv' => (float) $bestRule->getPpv(),
                'hits_total' => $bestRule->getHitsTotal(),
                'hits_true_pos' => $bestRule->getHitsTruePos(),
                'hits_false_pos' => $bestRule->getHitsFalsePos(),
                'lead_time_sec' => $bestRule->getLeadTimeSec(),
                'lead_time_hours' => $bestRule->getLeadTimeSec() ? round($bestRule->getLeadTimeSec() / 3600) : null,
                'enabled' => $bestRule->isEnabled(),
                'promoted_at' => $bestRule->getPromotedAt()?->format(\DATE_ATOM),
            ] : null,
        ]);
    }
}
