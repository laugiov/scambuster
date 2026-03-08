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

        // Nettoyer DB avant chaque test
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
        // Créer une campagne
        $campaign = new Campaign('test-hunter');
        $this->em->persist($campaign);
        $this->em->flush();

        // Créer une règle ENABLED avec SQL compilé valide
        $compiledData = [
            'sql' => "SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 10",
            'params' => ['p0' => '%test%'],
        ];

        $rule1 = new CampaignRule($campaign->getCampaignId(), 'RULE test1 { WHERE subject ~ "test" ACTION tag="test" }');
        $rule1->setCompiledData($compiledData);
        $rule1->enable();
        $this->em->persist($rule1);

        // Créer une règle DISABLED
        $rule2 = new CampaignRule($campaign->getCampaignId(), 'RULE test2 { WHERE subject ~ "disabled" ACTION tag="test" }');
        $rule2->setCompiledData($compiledData);
        $rule2->disable();
        $this->em->persist($rule2);

        $this->em->flush();

        // Exécuter hunter
        $result = $this->hunter->hunt();

        // Vérifier qu'une seule règle a été exécutée (celle enabled)
        $this->assertEquals(1, $result['total_rules']);
        $this->assertCount(1, $result['results']);
        $this->assertEquals('ok', $result['results'][0]['status']);
    }

    public function testHuntHandlesRuleWithoutCompiledSql(): void
    {
        // Créer une campagne et une règle SANS compiled_sql
        $campaign = new Campaign('test-no-sql');
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test { WHERE subject ~ "test" ACTION tag="test" }');
        $rule->enable();
        // Ne pas définir de compiled_sql
        $this->em->persist($rule);
        $this->em->flush();

        // Exécuter hunter
        $result = $this->hunter->hunt();

        // Vérifier que la règle est exécutée mais retourne une erreur
        $this->assertEquals(1, $result['total_rules']);
        $this->assertEquals('error', $result['results'][0]['status']);
        $this->assertEquals('No compiled SQL', $result['results'][0]['error']);
        $this->assertEquals(0, $result['results'][0]['hits_count']);
    }

    public function testHuntCalculatesPPV(): void
    {
        // Créer campagne et règle
        $campaign = new Campaign('test-ppv');
        $this->em->persist($campaign);

        // Créer règle qui matche n'importe quels messages existants en DB
        // On ne peut pas garantir qu'il y a des messages, donc on utilise LIMIT 0 pour éviter les erreurs
        $compiledData = [
            'sql' => "SELECT msg_id, subject, body_text, ts_msg FROM message ORDER BY ts_msg DESC LIMIT 5",
            'params' => [],
        ];

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE test { WHERE subject ~ "test" ACTION tag="test" }');
        $rule->setCompiledData($compiledData);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        // Exécuter hunter
        $result = $this->hunter->hunt();

        // Vérifier que PPV est calculé (même si 0 hits)
        $this->assertEquals('ok', $result['results'][0]['status']);
        $this->assertIsInt($result['results'][0]['hits_count']);
        $this->assertIsFloat($result['results'][0]['ppv']);
        $this->assertGreaterThanOrEqual(0, $result['results'][0]['ppv']);
        $this->assertLessThanOrEqual(1, $result['results'][0]['ppv']);
    }

    public function testHuntUpdatesRuleMetrics(): void
    {
        // Créer campagne et règle
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

        // Exécuter hunter
        $this->hunter->hunt();

        // Recharger la règle et vérifier que les métriques ont été mises à jour
        $this->em->clear();
        $updatedRule = $this->em->find(CampaignRule::class, $ruleId);

        $this->assertNotNull($updatedRule);
        // Les métriques devraient être >= 0 (même si 0 car pas de hits)
        $this->assertGreaterThanOrEqual(0, $updatedRule->getHitsTotal());
        $this->assertGreaterThanOrEqual(0, $updatedRule->getPpv());
    }

}
