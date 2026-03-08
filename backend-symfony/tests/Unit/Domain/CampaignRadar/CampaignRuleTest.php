<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\CampaignRadar;

use App\Domain\CampaignRadar\CampaignRule;
use App\Domain\Exception\DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CampaignRuleTest extends TestCase
{
    private Uuid $campaignId;

    protected function setUp(): void
    {
        $this->campaignId = Uuid::v7();
    }

    public function testConstructorCreatesValidRule(): void
    {
        $dsl = 'RULE test { WHERE subject ~ "urgent" ACTION tag="test" }';
        $rule = new CampaignRule($this->campaignId, $dsl);

        $this->assertSame($this->campaignId, $rule->getCampaignId());
        $this->assertSame($dsl, $rule->getDsl());
        $this->assertSame(0.0, $rule->getPpv());
        $this->assertSame(0, $rule->getHitsTotal());
        $this->assertTrue($rule->isEnabled());
    }

    public function testConstructorThrowsOnEmptyDsl(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DSL rule cannot be empty');

        new CampaignRule($this->campaignId, '   ');
    }

    public function testSetCompiledSqlStoresSql(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $sql = "SELECT * FROM message WHERE subject ILIKE '%test%'";

        $rule->setCompiledSql($sql);

        // Vérifie que le SQL est stocké dans la structure compiledData
        $compiledData = $rule->getCompiledData();
        $this->assertIsArray($compiledData);
        $this->assertArrayHasKey('sql', $compiledData);
        $this->assertSame($sql, $compiledData['sql']);
    }

    public function testSetCompiledSqlThrowsOnEmptySql(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Compiled SQL cannot be empty');

        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->setCompiledSql('  ');
    }

    public function testUpdateMetricsCalculatesPpv(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 8, 2); // 10 hits, 8 vrais positifs, 2 faux positifs

        $this->assertSame(10, $rule->getHitsTotal());
        $this->assertSame(8, $rule->getHitsTruePos());
        $this->assertSame(2, $rule->getHitsFalsePos());
        $this->assertSame(0.8, $rule->getPpv()); // 8/10 = 0.8
    }

    public function testUpdateMetricsThrowsOnInvalidSum(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('truePos + falsePos must equal hits');

        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 6, 2); // 6+2 != 10
    }

    public function testUpdateMetricsThrowsOnNegativeValues(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Metrics must be >= 0');

        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(-1, 0, 0);
    }

    public function testIsPromotableReturnsFalseWhenCriteriaNotMet(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 6, 4); // PPV = 0.6 (trop basse)

        $this->assertFalse($rule->isPromotable());
    }

    public function testIsPromotableReturnsTrueWhenCriteriaMet(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 9, 1); // PPV = 0.9, hits = 10

        $this->assertTrue($rule->isPromotable());
    }

    public function testIsPromotableReturnsFalseWhenDisabled(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 9, 1); // PPV = 0.9, hits = 10
        $rule->disable();

        $this->assertFalse($rule->isPromotable());
    }

    public function testIsPromotableReturnsFalseWhenAlreadyPromoted(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 9, 1); // PPV = 0.9, hits = 10
        $rule->promote();

        $this->assertFalse($rule->isPromotable());
    }

    public function testPromoteThrowsWhenNotPromotable(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Rule is not promotable');

        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->promote();
    }

    public function testPromoteSetsPromotedAt(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->updateMetrics(10, 9, 1); // Promotable

        $rule->promote();

        $this->assertInstanceOf(\DateTimeImmutable::class, $rule->getPromotedAt());
    }

    public function testDisableSetsEnabledToFalse(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->disable();

        $this->assertFalse($rule->isEnabled());
    }

    public function testEnableSetsEnabledToTrue(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->disable();
        $rule->enable();

        $this->assertTrue($rule->isEnabled());
    }

    public function testSetLeadTimeSecStoresValue(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->setLeadTimeSec(3600);

        $this->assertSame(3600, $rule->getLeadTimeSec());
    }

    public function testSetLeadTimeSecThrowsOnNegativeValue(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Lead-time must be >= 0');

        $rule = new CampaignRule($this->campaignId, 'RULE test {}');
        $rule->setLeadTimeSec(-100);
    }

    public function testUpdateMetricsIsIncremental(): void
    {
        $rule = new CampaignRule($this->campaignId, 'RULE test {}');

        // First update
        $rule->updateMetrics(10, 8, 2); // PPV = 0.8
        $this->assertSame(10, $rule->getHitsTotal());
        $this->assertSame(0.8, $rule->getPpv());

        // Second update (cumulative)
        $rule->updateMetrics(5, 5, 0); // All true positives
        $this->assertSame(15, $rule->getHitsTotal()); // 10 + 5
        $this->assertSame(13, $rule->getHitsTruePos()); // 8 + 5
        $this->assertSame(2, $rule->getHitsFalsePos()); // 2 + 0
        $this->assertEqualsWithDelta(0.8667, $rule->getPpv(), 0.0001); // 13/15
    }
}
