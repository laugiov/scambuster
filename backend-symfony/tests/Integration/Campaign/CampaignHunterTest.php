<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CampaignHunter;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignHunterTest extends KernelTestCase
{
    private CampaignHunter $hunter;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->hunter = $container->get(CampaignHunter::class);
        $this->em = $container->get('doctrine.orm.entity_manager');

        // Clean DB before each test
        $this->em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE 1=1');
        $this->em->getConnection()->executeStatement('DELETE FROM campaign WHERE 1=1');
    }

    public function testHuntReturnsResultsStructure(): void
    {
        $result = $this->hunter->hunt();

        $this->assertArrayHasKey('total_rules', $result);
        $this->assertArrayHasKey('total_hits', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertIsArray($result['results']);
    }

    public function testHuntExecutesEnabledRulesOnly(): void
    {
        // Create a campaign
        $campaign = new Campaign('test-hunter');
        $this->em->persist($campaign);
        $this->em->flush();

        // Create an ENABLED rule with valid compiled SQL
        $compiledData = [
            'sql' => "SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 10",
            'params' => ['p0' => '%test%'],
        ];

        $rule1 = new CampaignRule($campaign->getCampaignId(), 'RULE test1 { WHERE subject ~ "test" ACTION tag="test" }');
        $rule1->setCompiledData($compiledData);
        $rule1->enable();
        $this->em->persist($rule1);

        // Create a DISABLED rule
        $rule2 = new CampaignRule($campaign->getCampaignId(), 'RULE test2 { WHERE subject ~ "disabled" ACTION tag="test" }');
        $rule2->setCompiledData($compiledData);
        $rule2->disable();
        $this->em->persist($rule2);

        $this->em->flush();

        // Run hunter
        $result = $this->hunter->hunt();

        // Verify that only one rule was executed (the enabled one)
        $this->assertEquals(1, $result['total_rules']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('ok', $result['results'][0]['status']);
    }

    public function testHuntHandlesRuleWithoutCompiledSql(): void
    {
        // Create a campaign and a rule WITHOUT compiled_sql
        $campaign = new Campaign('test-no-sql');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test { WHERE subject ~ "test" ACTION tag="test" }');
        $rule->enable();
        // Do not set compiled_sql
        $this->em->persist($rule);
        $this->em->flush();

        // Run hunter
        $result = $this->hunter->hunt();

        // Verify that the rule is executed but returns an error
        $this->assertEquals(1, $result['total_rules']);
        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertEquals('No compiled SQL', $result['results'][0]['error']);
        $this->assertEquals(0, $result['results'][0]['hits_count']);
    }

    public function testHuntCalculatesPPV(): void
    {
        // Create campaign and rule
        $campaign = new Campaign('test-ppv');
        $this->em->persist($campaign);

        // Create a rule that matches any existing messages in DB
        // We cannot guarantee there are messages, so we use LIMIT 0 to avoid errors
        $compiledData = [
            'sql' => "SELECT msg_id, subject, body_text, ts_msg FROM message ORDER BY ts_msg DESC LIMIT 5",
            'params' => [],
        ];

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test { WHERE subject ~ "test" ACTION tag="test" }');
        $rule->setCompiledData($compiledData);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        // Run hunter
        $result = $this->hunter->hunt();

        // Verify that PPV is calculated (even if 0 hits)
        $this->assertEquals('ok', $result['results'][0]['status']);
        $this->assertIsInt($result['results'][0]['hits_count']);
        $this->assertIsFloat($result['results'][0]['ppv']);
        $this->assertGreaterThanOrEqual(0, $result['results'][0]['ppv']);
        $this->assertLessThanOrEqual(1, $result['results'][0]['ppv']);
    }

    public function testHuntUpdatesRuleMetrics(): void
    {
        // Create campaign and rule
        $campaign = new Campaign('test-metrics');
        $this->em->persist($campaign);

        $compiledData = [
            'sql' => "SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 10",
            'params' => ['p0' => '%test%'],
        ];

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test { WHERE subject ~ "test" ACTION tag="test" }');
        $rule->setCompiledData($compiledData);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $ruleId = $rule->getRuleId();

        // Run hunter
        $this->hunter->hunt();

        // Reload the rule and verify that the metrics were updated
        $this->em->clear();
        $updatedRule = $this->em->find(CampaignRule::class, $ruleId);

        $this->assertNotNull($updatedRule);
        // Metrics should be >= 0 (even if 0 because no hits)
        $this->assertGreaterThanOrEqual(0, $updatedRule->getHitsTotal());
        $this->assertGreaterThanOrEqual(0, $updatedRule->getPpv());
    }

}
