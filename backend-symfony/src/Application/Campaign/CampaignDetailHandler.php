<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CampaignDetailHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetail(string $campaignId): array
    {
        $campaign = $this->em->getRepository(Campaign::class)->find(Uuid::fromString($campaignId));

        if ($campaign === null) {
            throw new \RuntimeException('Campaign not found');
        }

        $rules = $this->em->getRepository(CampaignRule::class)->findBy(
            ['campaignId' => Uuid::fromString($campaignId)],
            ['ppv' => 'DESC']
        );

        $bestRule = $rules[0] ?? null;

        return [
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
        ];
    }
}
