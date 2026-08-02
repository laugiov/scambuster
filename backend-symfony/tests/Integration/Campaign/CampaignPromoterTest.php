<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CampaignPromoter;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignPromoterTest extends KernelTestCase
{
    private CampaignPromoter $promoter;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->promoter = $container->get(CampaignPromoter::class);
        $this->em = $container->get('doctrine.orm.entity_manager');

        // Nettoyer DB avant chaque test
        $this->em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE 1=1');
        $this->em->getConnection()->executeStatement('DELETE FROM campaign WHERE 1=1');
    }

    public function testGetThresholdsReturnsConfiguredValues(): void
    {
        $thresholds = $this->promoter->getThresholds();

        $this->assertSame(0.85, $thresholds['ppv_threshold']);
        $this->assertSame(5, $thresholds['min_hits']);
        $this->assertSame(10800, $thresholds['min_lead_time_sec']);
    }

    public function testPromoteThrowsWhenPpvTooLow(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('PPV too low');

        $campaign = new Campaign('test');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test {}');
        $rule->updateMetrics(10, 7, 3); // PPV = 0.7 (< 0.85)
        $this->em->persist($rule);
        $this->em->flush();

        $this->promoter->promote($rule->getRuleId());
    }

    public function testPromoteThrowsWhenNotEnoughHits(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Not enough hits');

        $campaign = new Campaign('test');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test {}');
        $rule->updateMetrics(3, 3, 0); // hits = 3 (< 5)
        $this->em->persist($rule);
        $this->em->flush();

        $this->promoter->promote($rule->getRuleId());
    }

    public function testPromoteSucceedsWhenThresholdsAreMet(): void
    {
        $campaign = new Campaign('test');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test {}');
        $rule->updateMetrics(10, 9, 1); // PPV = 0.9 (>= 0.85), hits = 10 (>= 5)
        $this->em->persist($rule);
        $this->em->flush();

        // Should not throw
        $this->promoter->promote($rule->getRuleId());

        // Verify promotion
        $this->em->refresh($rule);
        $this->assertNotNull($rule->getPromotedAt());
    }
}
