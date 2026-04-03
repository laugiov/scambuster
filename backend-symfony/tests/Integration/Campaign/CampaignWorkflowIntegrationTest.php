<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\CampaignHunter;
use App\Application\Campaign\CampaignPromoter;
use App\Application\Campaign\STIXExporter;
use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use App\Domain\CampaignRadar\CampaignStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Tests d'intégration pour le workflow complet Campaign Radar (Phases 2-5).
 * 
 * Scénarios testés:
 * - Hunter exécute règles shadow et collecte métriques
 * - Règles atteignent seuils de promotion
 * - Promotion manuelle via CampaignPromoter
 * - Export STIX avec tous types d'IoCs
 * - Gestion états campaign (shadow → promoted → archived)
 */
class CampaignWorkflowIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CampaignHunter $hunter;
    private CampaignPromoter $promoter;
    private STIXExporter $stixExporter;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->em = $container->get('doctrine')->getManager();
        $this->hunter = $container->get(CampaignHunter::class);
        $this->promoter = $container->get(CampaignPromoter::class);
        $this->stixExporter = $container->get(STIXExporter::class);
    }

    public function testCompleteWorkflowFromShadowToPromoted(): void
    {
        // ===== Phase 2-3: Créer campagne shadow avec règle =====
        $campaign = new Campaign(
            'test-hunter@integration',
            null,
            CampaignStatus::Shadow,
            new \DateTimeImmutable()
        );
        $campaign->setDslHash(hash('sha256', 'test-workflow-campaign'));
        $campaign->setSeverity(3);
        $campaign->setTlp('TLP:AMBER');
        $this->em->persist($campaign);

        $rule = new CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Test Workflow" WHERE subject.contains("test") ACTION flag_test'
        );
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 1000',
            'params' => ['p0' => '%test%']
        ]);
        $rule->enable();
        $this->em->persist($rule);
        $this->em->flush();

        $this->assertSame(CampaignStatus::Shadow, $campaign->getStatus());
        $this->assertNull($rule->getPromotedAt());

        // ===== Phase 4: Hunter exécute règle et met à jour métriques =====
        $result = $this->hunter->hunt();
        
        $this->assertIsArray($result);
        $this->assertIsArray($result);
        
        // Simuler collecte métriques après plusieurs runs
        $rule->updateMetrics(10, 9, 1); // PPV=0.9, hits=10 → promotable!
        $rule->setLeadTimeSec(14400); // 4h lead-time
        $this->em->flush();

        $this->assertGreaterThanOrEqual(0.85, $rule->getPpv());
        $this->assertGreaterThanOrEqual(5, $rule->getHitsTotal());
        $this->assertTrue($rule->isPromotable());

        // ===== Phase 5a: Évaluer candidats à promotion =====
        $candidates = $this->promoter->evaluateCandidates();
        
        $this->assertArrayHasKey('candidates', $candidates);
        $this->assertGreaterThanOrEqual(1, count($candidates['candidates']));

        // Vérifier structure candidat
        $candidate = $candidates['candidates'][0];
        $this->assertArrayHasKey('campaign_id', $candidate);
        $this->assertArrayHasKey('rule_id', $candidate);
        $this->assertArrayHasKey('ppv', $candidate);
        $this->assertGreaterThanOrEqual(0.85, $candidate['ppv']);

        // ===== Phase 5b: Promouvoir règle =====
        $this->promoter->promote($rule->getRuleId());
        $this->em->refresh($rule);
        $this->em->refresh($campaign);

        $this->assertNotNull($rule->getPromotedAt());
        $this->assertSame(CampaignStatus::Promoted, $campaign->getStatus());

        // ===== Phase 5c: Export STIX =====
        $campaign->setProfileYaml($this->getTestProfileYaml());
        $this->em->flush();

        $stixResult = $this->stixExporter->export($campaign);

        $this->assertArrayHasKey('file_path', $stixResult);
        $this->assertArrayHasKey('bundle_id', $stixResult);
        $this->assertFileExists($stixResult['file_path']);

        // Valider structure STIX 2.1
        $bundleJson = file_get_contents($stixResult['file_path']);
        $bundle = json_decode($bundleJson, true);

        $this->assertSame('bundle', $bundle['type']);
        $this->assertArrayNotHasKey('spec_version', $bundle); // STIX 2.1: no spec_version on bundle
        $this->assertArrayHasKey('objects', $bundle);
        $this->assertGreaterThanOrEqual(3, count($bundle['objects'])); // marking + identity + report minimum

        // Vérifier TLP marking-definition
        $marking = array_values(array_filter($bundle['objects'], fn ($obj) => $obj['type'] === 'marking-definition'))[0];
        $this->assertSame('tlp', $marking['definition_type']);
        $this->assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    public function testPromotionThresholdsValidation(): void
    {
        $campaign = new Campaign('test-thresholds@integration');
        $campaign->setDslHash(hash('sha256', 'threshold-test'));
        $this->em->persist($campaign);

        // Règle avec PPV trop faible
        $ruleLowPPV = new CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Low PPV" WHERE subject.contains("low") ACTION flag'
        );
        $ruleLowPPV->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $ruleLowPPV->enable();
        $ruleLowPPV->updateMetrics(10, 7, 3); // PPV=0.7 < 0.85
        $this->em->persist($ruleLowPPV);

        // Règle avec hits insuffisants
        $ruleLowHits = new CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Low Hits" WHERE subject.contains("rare") ACTION flag'
        );
        $ruleLowHits->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $ruleLowHits->enable();
        $ruleLowHits->updateMetrics(3, 3, 0); // PPV=1.0 mais hits=3 < 5
        $this->em->persist($ruleLowHits);

        $this->em->flush();

        // Tenter promotion de règle avec PPV trop faible
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/PPV too low/');
        $this->promoter->promote($ruleLowPPV->getRuleId());
    }

    public function testPromotionRequiresMinimumHits(): void
    {
        $campaign = new Campaign('test-hits@integration');
        $campaign->setDslHash(hash('sha256', 'hits-test'));
        $this->em->persist($campaign);

        $rule = new CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Test" WHERE subject.contains("test") ACTION flag'
        );
        $rule->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $rule->enable();
        $rule->updateMetrics(3, 3, 0); // PPV=1.0 mais hits=3 < MIN_HITS(5)
        $this->em->persist($rule);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Not enough hits/');
        $this->promoter->promote($rule->getRuleId());
    }

    public function testSTIXExportWithMultipleIoCTypes(): void
    {
        $campaign = new Campaign('test-stix@integration');
        $campaign->setDslHash(hash('sha256', 'stix-test'));
        $campaign->setTlp('TLP:RED');
        $campaign->setProfileYaml($this->getCompleteProfileYaml());
        $this->em->persist($campaign);
        $this->em->flush();

        $result = $this->stixExporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundle = json_decode(file_get_contents($result['file_path']), true);
        $indicators = array_filter($bundle['objects'], fn($obj) => $obj['type'] === 'indicator');

        // Vérifier présence de différents types d'IoCs
        $patterns = array_map(fn($ind) => $ind['pattern'], $indicators);
        $patternsStr = implode('|', $patterns);

        $this->assertStringContainsString('domain-name:value', $patternsStr, 'Missing domain IoC');
        $this->assertStringContainsString('email-addr:value', $patternsStr, 'Missing email IoC');
        $this->assertStringContainsString('url:value', $patternsStr, 'Missing URL IoC');
        $this->assertStringContainsString('ipv4-addr:value', $patternsStr, 'Missing IP IoC');
        $this->assertStringContainsString('file:hashes', $patternsStr, 'Missing file hash IoC');
    }

    public function testCampaignStateTransitions(): void
    {
        // Shadow → Promoted
        $campaign = new Campaign('test-states@integration', null, CampaignStatus::Shadow);
        $campaign->setDslHash(hash('sha256', 'state-test'));
        $this->em->persist($campaign);

        $rule = new CampaignRule($campaign->getCampaignId(), 'RULE "Test" WHERE true ACTION flag');
        $rule->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $rule->enable();
        $rule->updateMetrics(10, 9, 1);
        $this->em->persist($rule);
        $this->em->flush();

        $this->assertSame(CampaignStatus::Shadow, $campaign->getStatus());

        // Promotion
        $campaign->promote();
        $this->assertSame(CampaignStatus::Promoted, $campaign->getStatus());

        // Archived
        $campaign->archive();
        $this->assertSame(CampaignStatus::Archived, $campaign->getStatus());

        // Tenter promotion après archive (devrait échouer)
        $this->expectException(\App\Domain\Exception\DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot promote/');
        $campaign->promote();
    }

    public function testHunterExecutesOnlyEnabledRules(): void
    {
        $campaign = new Campaign('test-enabled@integration');
        $campaign->setDslHash(hash('sha256', 'enabled-test'));
        $this->em->persist($campaign);

        $ruleEnabled = new CampaignRule($campaign->getCampaignId(), 'RULE "Enabled" WHERE true ACTION flag');
        $ruleEnabled->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message LIMIT 1',
            'params' => []
        ]);
        $ruleEnabled->enable();
        $this->em->persist($ruleEnabled);

        $ruleDisabled = new CampaignRule($campaign->getCampaignId(), 'RULE "Disabled" WHERE true ACTION flag');
        $ruleDisabled->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $ruleDisabled->disable();
        $this->em->persist($ruleDisabled);

        $this->em->flush();

        $result = $this->hunter->hunt();

        // Vérifier que seule la règle enabled a été exécutée
        $enabledResult = array_values(array_filter(
            $result['results'],
            fn($r) => $r['rule_id'] === $ruleEnabled->getRuleId()->toRfc4122()
        ))[0] ?? null;

        $disabledResult = array_values(array_filter(
            $result['results'],
            fn($r) => $r['rule_id'] === $ruleDisabled->getRuleId()->toRfc4122()
        ))[0] ?? null;

        $this->assertNotNull($enabledResult);
        $this->assertNull($disabledResult, 'Disabled rule should not be executed');
    }

    private function getTestProfileYaml(): string
    {
        return <<<'YAML'
campaign:
  summary: "Test phishing campaign"
  tactics: ["Phishing"]
  risk: 3
infra:
  domains: ["test-phish.com"]
  emails: ["scam@test-phish.com"]
YAML;
    }

    private function getCompleteProfileYaml(): string
    {
        return <<<'YAML'
campaign:
  summary: "Complete IoC test campaign"
  tactics: ["Malware", "Phishing", "C2"]
  risk: 5
  urls: ["https://malicious.example.com/payload"]
infra:
  domains: ["malicious.example.com", "evil-domain.net"]
  emails: ["attacker@evil-domain.net"]
  ip_addresses: ["192.0.2.100", "198.51.100.50"]
  phone_numbers: ["+33123456789"]
malware:
  hashes: ["5d41402abc4b2a76b9719d911017c592", "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"]
  families: ["AsyncRAT"]
YAML;
    }
}
