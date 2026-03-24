<?php

declare(strict_types=1);

namespace App\UI\Http\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/campaign/{campaign_id}', name: 'api_campaign_detail', methods: ['GET'])]
final class GetCampaignDetailController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $campaign_id): JsonResponse
    {
        $campaign = $this->em->getRepository(Campaign::class)->find($campaign_id);

        if (!$campaign) {
            return new JsonResponse(['error' => 'Campaign not found'], Response::HTTP_NOT_FOUND);
        }

        $rules = $this->em->getRepository(CampaignRule::class)->findBy(
            ['campaign' => $campaign],
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
