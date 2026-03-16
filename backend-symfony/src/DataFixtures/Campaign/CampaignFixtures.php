<?php

declare(strict_types=1);

namespace App\DataFixtures\Campaign;

use App\Domain\CampaignRadar\Campaign;
use App\Domain\CampaignRadar\CampaignRule;
use App\Domain\CampaignRadar\CampaignStatus;
use App\Domain\CampaignRadar\MessageCampaign;
use App\Domain\Communication\Message;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

/**
 * Fixtures complètes pour Campaign Radar Phase 2-5.
 *
 * Scénarios couverts:
 * - Campaign shadow avec règles candidates à promotion
 * - Campaign promoted avec règles actives
 * - Campaign archived
 * - Règles avec différents PPV, hits, lead-time
 * - Messages détectés par campagne (MessageCampaign)
 * - Profils LLM (YAML) avec différents types d'IoCs
 */
class CampaignFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $messages = $manager->getRepository(Message::class)->findAll();

        if (empty($messages)) {
            // Pas de messages, skip fixtures
            return;
        }

        // ===== Campaign 1: Shadow - Candidate à promotion (PPV=0.9, hits=10) =====
        $campaign1 = new Campaign(
            'llm-hunter@gpt-4o-mini',
            Uuid::fromString('10000000-0000-0000-0000-000000000001'),
            CampaignStatus::Shadow,
            new \DateTimeImmutable('-7 days')
        );
        $campaign1->setDslHash(hash('sha256', 'paypal-phishing-campaign-1'));
        $campaign1->setSeverity(4);
        $campaign1->setTlp('TLP:AMBER');
        $campaign1->setActorGuess('APT-Phish-2024');
        $campaign1->setProfileYaml($this->getPayPalPhishingProfile());
        $campaign1->setCentroidSimhash(md5('paypal-centroid'));
        $manager->persist($campaign1);

        // Règle 1.1: Promotable (PPV=0.92, hits=12, lead_time=4h)
        $rule11 = new CampaignRule(
            $campaign1->getCampaignId(),
            'RULE "PayPal Urgent Action" WHERE subject.contains("PayPal") AND subject.contains("urgent") ACTION flag_phishing',
            Uuid::fromString('11000000-0000-0000-0000-000000000001')
        );
        $rule11->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 AND subject ILIKE :p1 LIMIT 1000',
            'params' => ['p0' => '%PayPal%', 'p1' => '%urgent%']
        ]);
        $rule11->enable();
        $rule11->updateMetrics(12, 11, 1); // hits=12, truePos=11, falsePos=1 → PPV=0.9166
        $rule11->setLeadTimeSec(14400); // 4 heures
        $manager->persist($rule11);

        // Règle 1.2: Non-promotable (PPV=0.75, hits insuffisants)
        $rule12 = new CampaignRule(
            $campaign1->getCampaignId(),
            'RULE "PayPal Generic" WHERE subject.contains("PayPal") ACTION flag_suspicious',
            Uuid::fromString('11000000-0000-0000-0000-000000000002')
        );
        $rule12->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE subject ILIKE :p0 LIMIT 1000',
            'params' => ['p0' => '%PayPal%']
        ]);
        $rule12->enable();
        $rule12->updateMetrics(4, 3, 1); // PPV=0.75, hits=4 < MIN_HITS
        $manager->persist($rule12);

        // ===== Campaign 2: Promoted avec règle active en production =====
        $campaign2 = new Campaign(
            'llm-hunter@gpt-4o-mini',
            Uuid::fromString('10000000-0000-0000-0000-000000000002'),
            CampaignStatus::Promoted,
            new \DateTimeImmutable('-30 days')
        );
        $campaign2->setDslHash(hash('sha256', 'bank-wire-transfer-scam'));
        $campaign2->setSeverity(5);
        $campaign2->setTlp('TLP:AMBER');
        $campaign2->setActorGuess('Nigerian-419-Group');
        $campaign2->setProfileYaml($this->getBankWireScamProfile());
        $campaign2->setCentroidSimhash(md5('bank-wire-centroid'));
        $manager->persist($campaign2);

        // Règle 2.1: Promue et active (excellent PPV=0.95, hits=50)
        $rule21 = new CampaignRule(
            $campaign2->getCampaignId(),
            'RULE "Wire Transfer Urgent" WHERE body.contains("wire transfer") AND body.contains("urgent") AND body.contains("IBAN") ACTION flag_high_risk',
            Uuid::fromString('11000000-0000-0000-0000-000000000003')
        );
        $rule21->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE body_text ILIKE :p0 AND body_text ILIKE :p1 AND body_text ILIKE :p2 LIMIT 1000',
            'params' => ['p0' => '%wire transfer%', 'p1' => '%urgent%', 'p2' => '%IBAN%']
        ]);
        $rule21->enable();
        $rule21->updateMetrics(50, 48, 2); // PPV=0.96
        $rule21->setLeadTimeSec(7200); // 2 heures
        $rule21->promote(); // Marque comme promue
        $manager->persist($rule21);

        // ===== Campaign 3: Shadow - Multiple IoC types pour test STIX =====
        $campaign3 = new Campaign(
            'llm-profiler@gpt-4o',
            Uuid::fromString('10000000-0000-0000-0000-000000000003'),
            CampaignStatus::Shadow,
            new \DateTimeImmutable('-3 days')
        );
        $campaign3->setDslHash(hash('sha256', 'malware-delivery-campaign'));
        $campaign3->setSeverity(5);
        $campaign3->setTlp('TLP:RED');
        $campaign3->setActorGuess('APT-Malware-2024');
        $campaign3->setProfileYaml($this->getMalwareDeliveryProfile());
        $campaign3->setCentroidSimhash(md5('malware-centroid'));
        $manager->persist($campaign3);

        $rule31 = new CampaignRule(
            $campaign3->getCampaignId(),
            'RULE "Malware Attachment" WHERE body.contains("invoice") AND has_attachment ACTION flag_malware',
            Uuid::fromString('11000000-0000-0000-0000-000000000004')
        );
        $rule31->setCompiledData([
            'sql' => 'SELECT msg_id, subject, body_text, ts_msg FROM message WHERE body_text ILIKE :p0 LIMIT 1000',
            'params' => ['p0' => '%invoice%']
        ]);
        $rule31->enable();
        $rule31->updateMetrics(8, 7, 1); // PPV=0.875
        $manager->persist($rule31);

        // ===== Campaign 4: Archived (ancienne campagne) =====
        $campaign4 = new Campaign(
            'llm-hunter@gpt-3.5-turbo',
            Uuid::fromString('10000000-0000-0000-0000-000000000004'),
            CampaignStatus::Archived,
            new \DateTimeImmutable('-60 days')
        );
        $campaign4->setDslHash(hash('sha256', 'old-lottery-scam'));
        $campaign4->setSeverity(2);
        $campaign4->setTlp('TLP:GREEN');
        // $campaign4->archive(); // Already archived by constructor
        $manager->persist($campaign4);

        // ===== MessageCampaign: Lier messages aux campagnes =====
        // Lier quelques messages à campaign1 pour simuler détection
        if (count($messages) >= 3) {
            $msgCamp1 = new MessageCampaign(
                Uuid::fromString($messages[0]->getMsgId()),
                $campaign1->getCampaignId(),
                0.87,
                'shadow-hunter-v1'
            );
            $manager->persist($msgCamp1);

            $msgCamp2 = new MessageCampaign(
                Uuid::fromString($messages[1]->getMsgId()),
                $campaign1->getCampaignId(),
                0.92,
                'shadow-hunter-v1'
            );
            $manager->persist($msgCamp2);

            $msgCamp3 = new MessageCampaign(
                Uuid::fromString($messages[2]->getMsgId()),
                $campaign2->getCampaignId(),
                0.95,
                'promoted-rule-v1'
            );
            $manager->persist($msgCamp3);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            \App\DataFixtures\Communication\MessageFixtures::class,
        ];
    }

    /**
     * Profil YAML PayPal phishing avec domains, URLs, emails.
     */
    private function getPayPalPhishingProfile(): string
    {
        return <<<'YAML'
campaign:
  summary: "PayPal urgent action phishing campaign targeting French users"
  tactics: ["Credential Phishing", "Social Engineering"]
  risk: 4
  sender_emails: ["noreply@paypal-secure.com", "support@paypal-verify.net"]
  urls: ["https://paypal-secure.com/login", "https://paypal-verify.net/account/verify"]
variants:
  subjects: 
    - "Urgent: Verify your PayPal account"
    - "Your PayPal account has been limited"
  display_names:
    - "PayPal Support"
    - "PayPal Security Team"
  url_shapes: ["https://paypal-{word}.com/login", "https://paypal-{word}.net/verify"]
infra:
  domains: ["paypal-secure.com", "paypal-verify.net", "paypal-alert.org"]
  emails: ["noreply@paypal-secure.com", "support@paypal-verify.net"]
  phone_numbers: ["+33 1 76 54 32 10"]
  ip_addresses: ["185.220.101.45"]
YAML;
    }

    /**
     * Profil YAML Bank Wire Transfer scam avec IBAN, domains.
     */
    private function getBankWireScamProfile(): string
    {
        return <<<'YAML'
campaign:
  summary: "CEO fraud / wire transfer scam targeting finance departments"
  tactics: ["BEC", "Wire Transfer Fraud"]
  risk: 5
  sender_emails: ["ceo@fake-company-mail.com"]
variants:
  subjects:
    - "Urgent wire transfer needed"
    - "CONFIDENTIAL: Immediate payment required"
  display_names:
    - "CEO John Smith"
    - "CFO"
infra:
  domains: ["fake-company-mail.com", "urgent-payment.biz"]
  emails: ["ceo@fake-company-mail.com", "finance@urgent-payment.biz"]
  iban: ["FR7630006000011234567890189", "DE89370400440532013000"]
YAML;
    }

    /**
     * Profil YAML Malware delivery avec file hashes, C2 servers.
     */
    private function getMalwareDeliveryProfile(): string
    {
        return <<<'YAML'
campaign:
  summary: "Malware delivery via invoice-themed emails with malicious attachments"
  tactics: ["Malware Delivery", "RAT Installation"]
  risk: 5
malware:
  hashes: ["e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", "5d41402abc4b2a76b9719d911017c592"]
  families: ["AsyncRAT", "AgentTesla"]
infra:
  domains: ["invoice-delivery.xyz", "doc-share.online"]
  c2_servers: ["185.220.101.50", "192.0.2.100"]
  urls: ["https://invoice-delivery.xyz/documents/invoice_2024.pdf", "https://doc-share.online/files/Q4_report.xlsx"]
YAML;
    }
}
