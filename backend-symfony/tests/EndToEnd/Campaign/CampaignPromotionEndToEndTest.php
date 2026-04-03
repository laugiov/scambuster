<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Campaign;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests End-to-End pour Campaign Promotion & STIX Export (Phase 5).
 * 
 * Teste le workflow complet via API REST:
 * 1. GET /api/v1/campaign/candidates - Liste candidats à promotion
 * 2. POST /api/v1/campaign/rule/{ruleId}/promote - Promotion manuelle
 * 3. POST /api/v1/campaign/{campaignId}/export/stix - Export STIX 2.1
 */
class CampaignPromotionEndToEndTest extends WebTestCase
{
    private function getValidJwt($client): string
    {
        $client->request('POST', '/api/v1/auth/login', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'admin@example.com',
            'password' => 'Un1que$trongPassword2024',
        ]));
        
        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $data);
        
        return $data['access_token'];
    }

    public function testGetPromotionCandidatesReturnsValidStructure(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        $client->request(
            'GET',
            '/api/v1/campaign/candidates',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt
            ]
        );

        $this->assertResponseIsSuccessful();
        
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('candidates', $data);
        $this->assertIsArray($data['candidates']);

        // Si au moins un candidat, vérifier structure
        if (count($data['candidates']) > 0) {
            $candidate = $data['candidates'][0];
            $this->assertArrayHasKey('campaign_id', $candidate);
            $this->assertArrayHasKey('rule_id', $candidate);
            $this->assertArrayHasKey('ppv', $candidate);
            $this->assertArrayHasKey('hits_total', $candidate);
            $this->assertArrayHasKey('lead_time_sec', $candidate);
            
            // Validation seuils
            $this->assertGreaterThanOrEqual(0.85, $candidate['ppv'], 'Candidate PPV should be >= 0.85');
            $this->assertGreaterThanOrEqual(5, $candidate['hits_total'], 'Candidate hits should be >= 5');
        }
    }

    public function testPromoteCampaignRuleWorkflow(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Créer une campagne promotable
        $em = $client->getContainer()->get('doctrine')->getManager();
        
        $campaign = new \App\Domain\CampaignRadar\Campaign(
            'e2e-test@gpt-4o',
            null,
            \App\Domain\CampaignRadar\CampaignStatus::Shadow
        );
        $campaign->setDslHash(hash('sha256', 'e2e-promotable-campaign'));
        $campaign->setSeverity(4);
        $campaign->setTlp('TLP:AMBER');
        $campaign->setProfileYaml($this->getTestProfileYaml());
        $em->persist($campaign);

        $rule = new \App\Domain\CampaignRadar\CampaignRule(
            $campaign->getCampaignId(),
            'RULE "E2E Test" WHERE subject.contains("test") ACTION flag_phishing'
        );
        $rule->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 10',
            'params' => ['p0' => '%test%']
        ]);
        $rule->enable();
        $rule->updateMetrics(10, 9, 1); // PPV=0.9, hits=10 → promotable
        $rule->setLeadTimeSec(14400);
        $em->persist($rule);
        $em->flush();

        $ruleId = $rule->getRuleId()->toRfc4122();
        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Étape 1: Vérifier que règle apparaît dans candidats
        $client->request(
            'GET',
            '/api/v1/campaign/candidates',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $candidatesData = json_decode($client->getResponse()->getContent(), true);
        $candidateIds = array_column($candidatesData['candidates'], 'rule_id');
        $this->assertContains($ruleId, $candidateIds, 'Promotable rule should appear in candidates');

        // Étape 2: Promouvoir la règle
        $client->request(
            'POST',
            '/api/v1/campaign/rule/' . $ruleId . '/promote',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();

        $promoteData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $promoteData);
        $this->assertArrayHasKey('rule_id', $promoteData);
        $this->assertSame('Rule promoted successfully', $promoteData['message']);

        // Étape 3: Vérifier état en DB
        $em->clear();
        $ruleAfter = $em->find(\App\Domain\CampaignRadar\CampaignRule::class, $rule->getRuleId());
        $campaignAfter = $em->find(\App\Domain\CampaignRadar\Campaign::class, $campaign->getCampaignId());
        
        $this->assertNotNull($ruleAfter->getPromotedAt());
        $this->assertSame(\App\Domain\CampaignRadar\CampaignStatus::Promoted, $campaignAfter->getStatus());

        // Étape 4: Export STIX
        $client->request(
            'POST',
            '/api/v1/campaign/' . $campaignId . '/export/stix',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();

        $stixData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $stixData);
        $this->assertArrayHasKey('file_path', $stixData);
        $this->assertArrayHasKey('bundle_id', $stixData);
        $this->assertSame('STIX export completed', $stixData['message']);
        
        // Vérifier que fichier STIX existe et est valide
        $this->assertFileExists($stixData['file_path']);
        
        $bundleContent = file_get_contents($stixData['file_path']);
        $bundle = json_decode($bundleContent, true);
        
        $this->assertIsArray($bundle);
        $this->assertSame('bundle', $bundle['type']);
        $this->assertArrayNotHasKey('spec_version', $bundle); // STIX 2.1: no spec_version on bundle
        $this->assertArrayHasKey('objects', $bundle);
        $this->assertGreaterThanOrEqual(3, count($bundle['objects'])); // marking + identity + report minimum

        // Vérifier présence indicateurs
        $indicators = array_filter($bundle['objects'], fn($obj) => $obj['type'] === 'indicator');
        $this->assertGreaterThanOrEqual(1, count($indicators), 'Bundle should contain at least one indicator');
    }

    public function testPromoteRuleWithLowPPVReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Créer une règle avec PPV trop faible
        $em = $client->getContainer()->get('doctrine')->getManager();
        
        $campaign = new \App\Domain\CampaignRadar\Campaign(
            'e2e-lowppv@test',
            null,
            \App\Domain\CampaignRadar\CampaignStatus::Shadow
        );
        $campaign->setDslHash(hash('sha256', 'e2e-low-ppv'));
        $em->persist($campaign);

        $rule = new \App\Domain\CampaignRadar\CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Low PPV" WHERE subject.contains("test") ACTION flag'
        );
        $rule->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $rule->enable();
        $rule->updateMetrics(10, 7, 3); // PPV=0.7 < 0.85
        $em->persist($rule);
        $em->flush();

        $ruleId = $rule->getRuleId()->toRfc4122();

        // Tenter promotion (devrait échouer)
        $client->request(
            'POST',
            '/api/v1/campaign/rule/' . $ruleId . '/promote',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(400);

        $errorData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $errorData);
        $this->assertArrayHasKey('message', $errorData);
        $this->assertStringContainsString('PPV', $errorData['message']);
    }

    public function testPromoteRuleWithLowHitsReturns400(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Créer une règle avec hits insuffisants
        $em = $client->getContainer()->get('doctrine')->getManager();
        
        $campaign = new \App\Domain\CampaignRadar\Campaign(
            'e2e-lowhits@test',
            null,
            \App\Domain\CampaignRadar\CampaignStatus::Shadow
        );
        $campaign->setDslHash(hash('sha256', 'e2e-low-hits'));
        $em->persist($campaign);

        $rule = new \App\Domain\CampaignRadar\CampaignRule(
            $campaign->getCampaignId(),
            'RULE "Low Hits" WHERE subject.contains("rare") ACTION flag'
        );
        $rule->setCompiledData(['sql' => 'SELECT 1', 'params' => []]);
        $rule->enable();
        $rule->updateMetrics(3, 3, 0); // PPV=1.0 mais hits=3 < 5
        $em->persist($rule);
        $em->flush();

        $ruleId = $rule->getRuleId()->toRfc4122();

        // Tenter promotion (devrait échouer)
        $client->request(
            'POST',
            '/api/v1/campaign/rule/' . $ruleId . '/promote',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseStatusCodeSame(400);

        $errorData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $errorData);
        $this->assertArrayHasKey('message', $errorData);
        $this->assertStringContainsString('hits', $errorData['message']);
    }

    public function testExportSTIXWithoutPII(): void
    {
        $client = static::createClient();
        $jwt = $this->getValidJwt($client);

        // Créer campagne avec profil contenant PII (sera filtré)
        $em = $client->getContainer()->get('doctrine')->getManager();
        
        $campaign = new \App\Domain\CampaignRadar\Campaign(
            'e2e-pii@test',
            null,
            \App\Domain\CampaignRadar\CampaignStatus::Promoted
        );
        $campaign->setDslHash(hash('sha256', 'e2e-pii-test'));
        $campaign->setTlp('TLP:AMBER');
        $campaign->setProfileYaml($this->getProfileWithPII());
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getCampaignId()->toRfc4122();

        // Export STIX
        $client->request(
            'POST',
            '/api/v1/campaign/' . $campaignId . '/export/stix',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]
        );

        $this->assertResponseIsSuccessful();
        
        $stixData = json_decode($client->getResponse()->getContent(), true);
        $bundleContent = file_get_contents($stixData['file_path']);
        
        // Vérifier qu'aucun email personnel n'apparaît (gmail, yahoo filtrés)
        $this->assertStringNotContainsString('gmail.com', $bundleContent);
        $this->assertStringNotContainsString('yahoo.com', $bundleContent);
        
        // Mais email professionnel devrait être présent
        $this->assertStringContainsString('scammer@evil.com', $bundleContent);
    }

    public function testUnauthorizedAccessReturns401(): void
    {
        $client = static::createClient();

        // Sans JWT
        $client->request('GET', '/api/v1/campaign/candidates');
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/v1/campaign/rule/fake-id/promote');
        $this->assertResponseStatusCodeSame(401);

        $client->request('POST', '/api/v1/campaign/fake-id/export/stix');
        $this->assertResponseStatusCodeSame(401);
    }

    private function getTestProfileYaml(): string
    {
        return <<<'YAML'
campaign:
  summary: "E2E test phishing campaign"
  tactics: ["Phishing"]
  risk: 4
infra:
  domains: ["e2e-test-phish.com", "fake-paypal.net"]
  emails: ["scammer@e2e-test-phish.com"]
  urls: ["https://e2e-test-phish.com/login"]
YAML;
    }

    private function getProfileWithPII(): string
    {
        return <<<'YAML'
campaign:
  summary: "Campaign with PII to test filtering"
  tactics: ["Phishing"]
  risk: 3
infra:
  domains: ["evil.com"]
  emails: ["scammer@evil.com", "personal@gmail.com", "test@yahoo.com"]
  urls: ["https://evil.com/phish"]
YAML;
    }
}
