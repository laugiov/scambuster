<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CampaignHunter;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests d'intégration RENFORCÉS pour CampaignHunter
 *
 * Couvre:
 * - Exécution avec SQL préparé (prepared statements)
 * - Calcul PPV (true_pos / total)
 * - Calcul lead-time avec pics
 * - Gestion erreurs multiples
 * - Update métriques DB
 * - Performance tracking
 * - Scénarios réalistes
 */
final class CampaignHunterEnhancedTest extends KernelTestCase
{
    private CampaignHunter $hunter;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->hunter = $container->get(CampaignHunter::class);
        $this->em = $container->get('doctrine.orm.entity_manager');

        // Cleanup
        $this->em->getConnection()->executeStatement('DELETE FROM campaign_rule WHERE 1=1');
        $this->em->getConnection()->executeStatement('DELETE FROM campaign WHERE 1=1');
        $this->em->clear();
    }

    // ==================== Tests Structure ====================

    public function testHuntReturnsCorrectStructure(): void
    {
        $result = $this->hunter->hunt();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('total_rules', $result);
        $this->assertArrayHasKey('total_hits', $result);
        $this->assertArrayHasKey('results', $result);
        $this->assertIsInt($result['total_rules']);
        $this->assertIsInt($result['total_hits']);
        $this->assertIsArray($result['results']);
    }

    public function testHuntWithNoRules(): void
    {
        $result = $this->hunter->hunt();

        $this->assertEquals(0, $result['total_rules']);
        $this->assertEquals(0, $result['total_hits']);
        $this->assertEmpty($result['results']);
    }

    // ==================== Tests Enabled/Disabled ====================

    public function testHuntExecutesOnlyEnabledRules(): void
    {
        $campaign = new Campaign('test-enabled');
        $this->em->persist($campaign);
        $this->em->flush();

        // Règle enabled
        $rule1 = new CampaignRule($campaign->getCampaignId(), 'RULE r1');
        $rule1->setCompiledData(['sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 5', 'params' => []]);
        $rule1->enable();
        $this->em->persist($rule1);

        // Règle disabled
        $rule2 = new CampaignRule($campaign->getCampaignId(), 'RULE r2');
        $rule2->setCompiledData(['sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 5', 'params' => []]);
        $rule2->disable();
        $this->em->persist($rule2);

        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals(1, $result['total_rules']);
        $this->assertCount(1, $result['results']);
    }

    public function testHuntExecutes5EnabledRules(): void
    {
        $campaign = new Campaign('test-multiple');
        $this->em->persist($campaign);

        for ($i = 1; $i <= 5; $i++) {
            $rule = new CampaignRule($campaign->getCampaignId(), "RULE r{$i}");
            $rule->setCompiledData(['sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 3', 'params' => []]);
            $rule->enable();
            $this->em->persist($rule);
        }

        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals(5, $result['total_rules']);
        $this->assertCount(5, $result['results']);
    }

    // ==================== Tests Gestion Erreurs ====================

    public function testHuntHandlesNoCompiledSql(): void
    {
        $campaign = new Campaign('test-no-sql');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals(1, $result['total_rules']);
        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertEquals('No compiled SQL', $result['results'][0]['error']);
    }

    public function testHuntHandlesInvalidJson(): void
    {
        $campaign = new Campaign('test-bad-json');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->enable();

        // Bypass validation avec reflection to set invalid data structure (missing 'sql' key)
        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('compiledSql');
        $property->setAccessible(true);
        $property->setValue($rule, ['invalid' => 'structure']); // Pas de clé 'sql'

        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        // With invalid structure, getCompiledData() returns data but without 'sql' key
        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertStringContainsString('No compiled SQL', $result['results'][0]['error']);
    }

    public function testHuntHandlesSqlError(): void
    {
        $campaign = new Campaign('test-sql-error');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData(['sql' => 'SELECT * FROM nonexistent_table', 'params' => []]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertArrayHasKey('error', $result['results'][0]);
    }

    public function testHuntValidatesCompiledDataStructure(): void
    {
        // This test verifies that setCompiledData() enforces the structure requirement
        $campaign = new Campaign('test-validation');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');

        // Attempt to set incomplete compiled data should throw exception
        $this->expectException(\App\Domain\Exception\DomainException::class);
        $this->expectExceptionMessage('Compiled data must contain sql and params keys');

        $rule->setCompiledData(['params' => []]); // Missing 'sql' key
    }

    public function testHuntValidatesCompiledDataMissingParams(): void
    {
        // This test verifies that setCompiledData() enforces the structure requirement
        $campaign = new Campaign('test-validation-params');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');

        // Attempt to set incomplete compiled data should throw exception
        $this->expectException(\App\Domain\Exception\DomainException::class);
        $this->expectExceptionMessage('Compiled data must contain sql and params keys');

        $rule->setCompiledData(['sql' => 'SELECT 1']); // Missing 'params' key
    }

    // ==================== Tests PPV ====================

    public function testHuntCalculatesPPVWith0Hits(): void
    {
        $campaign = new Campaign('test-ppv-0');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 10',
            'params' => ['p0' => '%impossible_string_xyz%']
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals('ok', $result['results'][0]['status']);
        $this->assertEquals(0, $result['results'][0]['hits_count']);
        $this->assertEquals(0.0, $result['results'][0]['ppv']);
    }

    public function testHuntCalculatesPPVWithHits(): void
    {
        $campaign = new Campaign('test-ppv-hits');
        $this->em->persist($campaign);

        // Rule that matches existing messages (if any)
        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message ORDER BY ts_msg DESC LIMIT 10',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals('ok', $result['results'][0]['status']);
        $this->assertIsFloat($result['results'][0]['ppv']);
        $this->assertGreaterThanOrEqual(0, $result['results'][0]['ppv']);
        $this->assertLessThanOrEqual(1, $result['results'][0]['ppv']);
    }

    // ==================== Tests Lead-Time ====================

    public function testHuntLeadTimeNullWhenInsufficientHits(): void
    {
        $campaign = new Campaign('test-leadtime');
        $this->em->persist($campaign);

        // Rule with LIMIT 2 (< 5 required for lead-time)
        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message ORDER BY ts_msg DESC LIMIT 2',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        // Lead-time should be null (insufficient hits)
        $this->assertNull($result['results'][0]['lead_time_sec']);
    }

    // ==================== Tests Metrics Update ====================

    public function testHuntUpdatesMetricsInDb(): void
    {
        $campaign = new Campaign('test-metrics');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 5',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $ruleId = $rule->getRuleId();

        // Execute hunt
        $this->hunter->hunt();

        // Reload and check metrics
        $this->em->clear();
        $reloaded = $this->em->find(CampaignRule::class, $ruleId);

        $this->assertNotNull($reloaded);
        $this->assertGreaterThanOrEqual(0, $reloaded->getHitsTotal());
        $this->assertGreaterThanOrEqual(0, $reloaded->getPpv());
    }

    public function testHuntUpdatesMetricsMultipleRuns(): void
    {
        $campaign = new Campaign('test-metrics-multi');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 3',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $ruleId = $rule->getRuleId();

        // Run 1
        $this->hunter->hunt();
        $this->em->clear();
        $after1 = $this->em->find(CampaignRule::class, $ruleId);
        $hits1 = $after1->getHitsTotal();

        // Run 2
        $this->hunter->hunt();
        $this->em->clear();
        $after2 = $this->em->find(CampaignRule::class, $ruleId);
        $hits2 = $after2->getHitsTotal();

        // Metrics should be updated
        $this->assertGreaterThanOrEqual($hits1, $hits2);
    }

    // ==================== Tests Performance ====================

    public function testHuntTracksLatency(): void
    {
        $campaign = new Campaign('test-latency');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 10',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertArrayHasKey('latency_ms', $result['results'][0]);
        $this->assertIsInt($result['results'][0]['latency_ms']);
        $this->assertGreaterThanOrEqual(0, $result['results'][0]['latency_ms']);
    }

    public function testHuntCompletesWithin10Seconds(): void
    {
        $campaign = new Campaign('test-perf');
        $this->em->persist($campaign);

        // Create 3 rules
        for ($i = 1; $i <= 3; $i++) {
            $rule = new CampaignRule($campaign->getCampaignId(), "RULE r{$i}");
            $rule->setCompiledData([
                'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 10',
                'params' => []
            ]);
            $rule->enable();
            $this->em->persist($rule);
        }

        $this->em->flush();

        $start = microtime(true);
        $this->hunter->hunt();
        $duration = microtime(true) - $start;

        $this->assertLessThan(10, $duration, "Hunt took {$duration}s, expected < 10s");
    }

    // ==================== Tests Prepared Statements ====================

    public function testHuntUsesPreparedStatements(): void
    {
        $campaign = new Campaign('test-prepared');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 5',
            'params' => ['p0' => '%test%']
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        // Should execute without SQL errors
        $this->assertEquals('ok', $result['results'][0]['status']);
    }

    public function testHuntPreparedStatementsWithMultipleParams(): void
    {
        $campaign = new Campaign('test-multi-params');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 AND body_text ILIKE :p1 LIMIT 5',
            'params' => ['p0' => '%test%', 'p1' => '%verify%']
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $this->assertEquals('ok', $result['results'][0]['status']);
    }

    // ==================== Tests Result Structure ====================

    public function testHuntResultContainsAllFields(): void
    {
        $campaign = new Campaign('test-result-fields');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test');
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 5',
            'params' => []
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $result = $this->hunter->hunt();

        $ruleResult = $result['results'][0];
        $this->assertArrayHasKey('rule_id', $ruleResult);
        $this->assertArrayHasKey('status', $ruleResult);
        $this->assertArrayHasKey('hits_count', $ruleResult);
        $this->assertArrayHasKey('ppv', $ruleResult);
        $this->assertArrayHasKey('lead_time_sec', $ruleResult);
        $this->assertArrayHasKey('latency_ms', $ruleResult);
    }
}
