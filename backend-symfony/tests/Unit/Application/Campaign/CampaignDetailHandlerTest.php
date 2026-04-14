<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\CampaignDetailHandler;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use App\Domain\CampaignRadar\CampaignStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class CampaignDetailHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CampaignDetailHandler $handler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->handler = new CampaignDetailHandler($this->em);
    }

    public function testThrowsWhenCampaignNotFound(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn(null);

        $this->em->method('getRepository')
            ->with(Campaign::class)
            ->willReturn($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Campaign not found');

        $this->handler->getDetail(Uuid::v4()->toRfc4122());
    }

    public function testReturnsDetailWithRule(): void
    {
        $uuid = Uuid::v4();
        $now = new \DateTimeImmutable();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($uuid);
        $campaign->method('getStatus')->willReturn(CampaignStatus::Shadow);
        $campaign->method('getSeverity')->willReturn(7);
        $campaign->method('getTlp')->willReturn('amber');
        $campaign->method('getFirstSeen')->willReturn($now);
        $campaign->method('getProfileYaml')->willReturn('yaml: true');
        $campaign->method('getNotes')->willReturn('some notes');
        $campaign->method('getCreatedAt')->willReturn($now);

        $ruleUuid = Uuid::v4();
        $rule = $this->createMock(CampaignRule::class);
        $rule->method('getRuleId')->willReturn($ruleUuid);
        $rule->method('getPpv')->willReturn(0.95);
        $rule->method('getHitsTotal')->willReturn(100);
        $rule->method('getHitsTruePos')->willReturn(95);
        $rule->method('getHitsFalsePos')->willReturn(5);
        $rule->method('getLeadTimeSec')->willReturn(3600);
        $rule->method('isEnabled')->willReturn(true);
        $rule->method('getPromotedAt')->willReturn($now);

        $campaignRepo = $this->createMock(EntityRepository::class);
        $campaignRepo->method('find')->willReturn($campaign);

        $ruleRepo = $this->createMock(EntityRepository::class);
        $ruleRepo->method('findBy')->willReturn([$rule]);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($campaignRepo, $ruleRepo) {
                return match ($class) {
                    Campaign::class => $campaignRepo,
                    CampaignRule::class => $ruleRepo,
                };
            });

        $result = $this->handler->getDetail($uuid->toRfc4122());

        $this->assertSame($uuid, $result['campaign_id']);
        $this->assertSame('shadow', $result['status']);
        $this->assertSame(7, $result['severity']);
        $this->assertSame('amber', $result['tlp']);
        $this->assertSame('yaml: true', $result['profile_yaml']);
        $this->assertSame('some notes', $result['notes']);
        $this->assertNotNull($result['rule']);
        $this->assertSame($ruleUuid, $result['rule']['rule_id']);
        $this->assertSame(0.95, $result['rule']['ppv']);
        $this->assertSame(100, $result['rule']['hits_total']);
        $this->assertSame(95, $result['rule']['hits_true_pos']);
        $this->assertSame(5, $result['rule']['hits_false_pos']);
        $this->assertSame(3600, $result['rule']['lead_time_sec']);
        $this->assertEquals(1.0, $result['rule']['lead_time_hours']);
        $this->assertTrue($result['rule']['enabled']);
    }

    public function testReturnsDetailWithNoRule(): void
    {
        $uuid = Uuid::v4();
        $now = new \DateTimeImmutable();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($uuid);
        $campaign->method('getStatus')->willReturn(CampaignStatus::Shadow);
        $campaign->method('getSeverity')->willReturn(3);
        $campaign->method('getTlp')->willReturn('white');
        $campaign->method('getFirstSeen')->willReturn($now);
        $campaign->method('getProfileYaml')->willReturn(null);
        $campaign->method('getNotes')->willReturn(null);
        $campaign->method('getCreatedAt')->willReturn($now);

        $campaignRepo = $this->createMock(EntityRepository::class);
        $campaignRepo->method('find')->willReturn($campaign);

        $ruleRepo = $this->createMock(EntityRepository::class);
        $ruleRepo->method('findBy')->willReturn([]);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($campaignRepo, $ruleRepo) {
                return match ($class) {
                    Campaign::class => $campaignRepo,
                    CampaignRule::class => $ruleRepo,
                };
            });

        $result = $this->handler->getDetail($uuid->toRfc4122());

        $this->assertNull($result['rule']);
    }

    public function testReturnsDetailWithRuleZeroLeadTime(): void
    {
        $uuid = Uuid::v4();
        $now = new \DateTimeImmutable();

        $campaign = $this->createMock(Campaign::class);
        $campaign->method('getCampaignId')->willReturn($uuid);
        $campaign->method('getStatus')->willReturn(CampaignStatus::Shadow);
        $campaign->method('getSeverity')->willReturn(5);
        $campaign->method('getTlp')->willReturn('green');
        $campaign->method('getFirstSeen')->willReturn($now);
        $campaign->method('getProfileYaml')->willReturn(null);
        $campaign->method('getNotes')->willReturn(null);
        $campaign->method('getCreatedAt')->willReturn($now);

        $ruleUuid = Uuid::v4();
        $rule = $this->createMock(CampaignRule::class);
        $rule->method('getRuleId')->willReturn($ruleUuid);
        $rule->method('getPpv')->willReturn(0.0);
        $rule->method('getHitsTotal')->willReturn(0);
        $rule->method('getHitsTruePos')->willReturn(0);
        $rule->method('getHitsFalsePos')->willReturn(0);
        $rule->method('getLeadTimeSec')->willReturn(0);
        $rule->method('isEnabled')->willReturn(false);
        $rule->method('getPromotedAt')->willReturn(null);

        $campaignRepo = $this->createMock(EntityRepository::class);
        $campaignRepo->method('find')->willReturn($campaign);

        $ruleRepo = $this->createMock(EntityRepository::class);
        $ruleRepo->method('findBy')->willReturn([$rule]);

        $this->em->method('getRepository')
            ->willReturnCallback(function (string $class) use ($campaignRepo, $ruleRepo) {
                return match ($class) {
                    Campaign::class => $campaignRepo,
                    CampaignRule::class => $ruleRepo,
                };
            });

        $result = $this->handler->getDetail($uuid->toRfc4122());

        // lead_time_sec = 0 is falsy, so lead_time_hours should be null
        $this->assertNull($result['rule']['lead_time_hours']);
        $this->assertNull($result['rule']['promoted_at']);
    }
}
