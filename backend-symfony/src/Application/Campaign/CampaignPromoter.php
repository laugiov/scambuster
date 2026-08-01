<?php

declare(strict_types=1);

namespace App\Application\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRepositoryInterface;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CampaignPromoter
{
    private const PPV_THRESHOLD = 0.85;
    private const MIN_HITS = 5;
    private const MIN_LEAD_TIME_SEC = 10800; // 3 hours

    public function __construct(
        private EntityManagerInterface $em,
        private CampaignRepositoryInterface $campaignRepository,
        private STIXExporter $stixExporter,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Evaluates promotion candidates.
     *
     * @return array{candidates: list<array{campaign_id: string, rule_id: string, ppv: float, hits_total: int, lead_time_sec: ?int, lead_time_hours: ?float, created_at: string}>, promoted: list<array{campaign_id: string, rule_id: string, ppv: float, hits_total: int, lead_time_sec: ?int, lead_time_hours: ?float, promoted_at: ?string}>}
     */
    public function evaluateCandidates(): array
    {
        $this->logger->info('Evaluating promotion candidates');

        // Use the SQL view created in Phase 1
        $candidates = $this->campaignRepository->findPromotionCandidates();

        $candidatesData = [];

        foreach ($candidates as $campaign) {
            // Retrieve rules for this campaign
            $rules = $this->em->getRepository(CampaignRule::class)
                ->findBy([
                    'campaignId' => $campaign->getCampaignId(),
                    'enabled' => true,
                ]);

            foreach ($rules as $rule) {
                if ($rule->isPromotable()) {
                    $candidatesData[] = [
                        'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
                        'rule_id' => $rule->getRuleId()->toRfc4122(),
                        'ppv' => $rule->getPpv(),
                        'hits_total' => $rule->getHitsTotal(),
                        'lead_time_sec' => $rule->getLeadTimeSec(),
                        'lead_time_hours' => $rule->getLeadTimeSec() ? round($rule->getLeadTimeSec() / 3600, 1) : null,
                        'created_at' => $rule->getCreatedAt()->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        // Also retrieve already promoted rules (last 20)
        /** @var list<CampaignRule> $promotedRules */
        $promotedRules = $this->em->getRepository(CampaignRule::class)
            ->createQueryBuilder('cr')
            ->where('cr.promotedAt IS NOT NULL')
            ->orderBy('cr.promotedAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $promotedData = array_map(fn (CampaignRule $r): array => [
            'campaign_id' => $r->getCampaignId()->toRfc4122(),
            'rule_id' => $r->getRuleId()->toRfc4122(),
            'ppv' => $r->getPpv(),
            'hits_total' => $r->getHitsTotal(),
            'lead_time_sec' => $r->getLeadTimeSec(),
            'lead_time_hours' => $r->getLeadTimeSec() ? round($r->getLeadTimeSec() / 3600, 1) : null,
            'promoted_at' => $r->getPromotedAt()?->format('Y-m-d H:i:s'),
        ], $promotedRules);

        $this->logger->info('Promotion candidates evaluated', [
            'candidates_count' => count($candidatesData),
            'promoted_count' => count($promotedData),
        ]);

        return [
            'candidates' => $candidatesData,
            'promoted' => $promotedData,
        ];
    }

    /**
     * Promotes a campaign rule.
     *
     * @throws \DomainException  si seuils non atteints
     * @throws \RuntimeException if the rule is not found
     */
    public function promote(Uuid $ruleId): void
    {
        $rule = $this->em->find(CampaignRule::class, $ruleId);

        if ($rule === null) {
            throw new \RuntimeException("Rule not found: {$ruleId->toRfc4122()}");
        }

        // Validation seuils
        if ($rule->getPpv() < self::PPV_THRESHOLD) {
            throw new \DomainException(sprintf(
                'PPV too low for promotion: %.4f (threshold: %.2f)',
                $rule->getPpv(),
                self::PPV_THRESHOLD
            ));
        }

        if ($rule->getHitsTotal() < self::MIN_HITS) {
            throw new \DomainException(sprintf(
                'Not enough hits for promotion: %d (minimum: %d)',
                $rule->getHitsTotal(),
                self::MIN_HITS
            ));
        }

        if ($rule->getLeadTimeSec() !== null && $rule->getLeadTimeSec() < self::MIN_LEAD_TIME_SEC) {
            $this->logger->warning('Lead-time below recommended threshold', [
                'rule_id' => $ruleId->toRfc4122(),
                'lead_time_sec' => $rule->getLeadTimeSec(),
                'min_lead_time_sec' => self::MIN_LEAD_TIME_SEC,
            ]);
        }

        // Promote the rule
        $rule->promote();

        // Promote the campaign
        $campaign = $this->em->find(Campaign::class, $rule->getCampaignId());

        if ($campaign !== null) {
            $campaign->promote();
        }

        $this->em->flush();

        $this->logger->info('Campaign rule promoted', [
            'rule_id' => $ruleId->toRfc4122(),
            'campaign_id' => $rule->getCampaignId()->toRfc4122(),
            'ppv' => $rule->getPpv(),
            'hits_total' => $rule->getHitsTotal(),
            'lead_time_sec' => $rule->getLeadTimeSec(),
        ]);

        // Export to STIX (if the campaign exists)
        if ($campaign !== null) {
            try {
                $this->stixExporter->export($campaign);
            } catch (\Throwable $e) {
                $this->logger->error('STIX export failed after promotion', [
                    'campaign_id' => $campaign->getCampaignId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Retrieves configured promotion thresholds.
     *
     * @return array{ppv_threshold: float, min_hits: int, min_lead_time_sec: int}
     */
    public function getThresholds(): array
    {
        return [
            'ppv_threshold' => self::PPV_THRESHOLD,
            'min_hits' => self::MIN_HITS,
            'min_lead_time_sec' => self::MIN_LEAD_TIME_SEC,
        ];
    }
}
