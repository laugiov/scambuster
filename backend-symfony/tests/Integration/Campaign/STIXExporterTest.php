<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\STIXExporter;
use App\Domain\CampaignRadar\Campaign;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class STIXExporterTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        $this->tmpPath = sys_get_temp_dir() . '/stix-test-' . uniqid();
        mkdir($this->tmpPath);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpPath . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        rmdir($this->tmpPath);
    }

    public function testExportGeneratesValidSTIXBundle(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        $campaign->setProfileYaml("campaign:\n  summary: Test\nvariants:\n  url_shapes: []\ninfra:\n  domains: [\"evil.com\"]");

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $this->assertSame('bundle', $bundle['type']);
        $this->assertSame('2.1', $bundle['spec_version']);
        $this->assertArrayHasKey('objects', $bundle);
        $this->assertNotEmpty($bundle['objects']);
    }

    public function testExportFiltersOutPersonalEmails(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        // Les emails personnels (gmail, yahoo, etc.) sont filtrés par extractEmails()
        $campaign->setProfileYaml("campaign:\n  summary: Test\nvariants: {}\ninfra:\n  domains: []\n  emails: [\"test@gmail.com\", \"contact@company.com\"]");

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);

        // Vérifier qu'aucun email gmail n'apparaît dans le bundle
        $this->assertStringNotContainsString('gmail.com', $bundleJson);

        // Mais l'email professionnel devrait être présent
        $this->assertStringContainsString('company.com', $bundleJson);
    }

    public function testExportHandlesEmptyProfile(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        // Pas de profile YAML

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Le bundle devrait contenir identity et report, mais pas d'indicators
        $this->assertCount(2, $bundle['objects']);
        $this->assertSame('identity', $bundle['objects'][0]['type']);
        $this->assertSame('report', $bundle['objects'][1]['type']);
    }

    public function testExportHandlesMalformedYaml(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        $campaign->setProfileYaml("invalid: yaml: [ unclosed");

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Le bundle devrait être créé sans indicators
        $this->assertArrayHasKey('objects', $bundle);
    }

    public function testExportGeneratesAllIoCTypes(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        $profileYaml = <<<YAML
campaign:
  summary: Test campaign with all IoC types
  urls: ["https://malicious.example.com/path"]
  phone_numbers: ["+33612345678"]
variants:
  url_shapes: ["https://evil.com/{id}"]
infra:
  domains: ["evil.com", "malicious.example.com"]
  emails: ["scammer@evil.com", "ignored@gmail.com"]
  phone_numbers: ["0123456789"]
  ip_addresses: ["192.168.1.100", "10.0.0.1"]
  file_hashes: ["e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"]
YAML;

        $campaign->setProfileYaml($profileYaml);

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Compter les indicators par type
        $indicators = array_filter($bundle['objects'], fn($obj) => $obj['type'] === 'indicator');

        // Vérifier qu'on a des indicators pour tous les types
        $patterns = array_map(fn($ind) => $ind['pattern'], $indicators);
        $patternsStr = implode('|', $patterns);

        // Domaines (2: evil.com, malicious.example.com)
        $this->assertStringContainsString('domain-name:value', $patternsStr);
        $this->assertStringContainsString('evil.com', $patternsStr);
        $this->assertStringContainsString('malicious.example.com', $patternsStr);

        // Emails (1: scammer@evil.com, gmail filtré)
        $this->assertStringContainsString('email-addr:value', $patternsStr);
        $this->assertStringContainsString('scammer@evil.com', $patternsStr);
        $this->assertStringNotContainsString('gmail.com', $patternsStr);

        // URLs (sans placeholders, donc 1 seule URL valide)
        $this->assertStringContainsString('url:value', $patternsStr);
        $this->assertStringContainsString('https://malicious.example.com/path', $patternsStr);

        // Phone numbers (2: +33612345678, 0123456789)
        $this->assertStringContainsString('x-phone-number:value', $patternsStr);

        // IP addresses (2: 192.168.1.100, 10.0.0.1)
        $this->assertStringContainsString('ipv4-addr:value', $patternsStr);
        $this->assertStringContainsString('192.168.1.100', $patternsStr);

        // File hashes (1)
        $this->assertStringContainsString('file:hashes.SHA256', $patternsStr);
        $this->assertStringContainsString('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', $patternsStr);

        // Au minimum: 2 domaines + 1 email + 1 URL + 2 phones + 2 IPs + 1 hash = 9 indicators
        $this->assertGreaterThanOrEqual(9, count($indicators));
    }

    public function testExportIncludesTLPLabel(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');
        $campaign->setProfileYaml("campaign:\n  summary: Test\ninfra:\n  domains: [\"evil.com\"]");

        $result = $exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Trouver le report object
        $report = null;
        foreach ($bundle['objects'] as $obj) {
            if ($obj['type'] === 'report') {
                $report = $obj;
                break;
            }
        }

        $this->assertNotNull($report);
        $this->assertArrayHasKey('labels', $report);

        // Vérifier que TLP est présent dans les labels
        $this->assertContains('campaign', $report['labels']);
        $this->assertContains('threat-report', $report['labels']);
        // Le TLP par défaut est TLP:AMBER
        $this->assertContains('TLP:AMBER', $report['labels']);
    }

    public function testExportExtractsIoCsFromMultipleSources(): void
    {
        $exporter = new STIXExporter(new NullLogger(), $this->tmpPath);

        $campaign = new Campaign('test');

        // Tester extraction depuis plusieurs chemins (variants.url_shapes, DSL rules, etc.)
        $profileYaml = <<<YAML
campaign:
  summary: Multi-source test
  sender_emails: ["phishing@evil.com"]
variants:
  url_shapes: ["https://phishing.example.com/login"]
infra:
  domains: ["evil.com"]
  c2_servers: ["203.0.113.1"]
rules:
  - "from.domain == 'evil.com' AND subject.contains('urgent')"
malware:
  hashes: ["d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2"]
YAML;

        $campaign->setProfileYaml($profileYaml);

        $result = $exporter->export($campaign);

        $bundleJson = file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $indicators = array_filter($bundle['objects'], fn($obj) => $obj['type'] === 'indicator');
        $patternsStr = implode('|', array_map(fn($ind) => $ind['pattern'], $indicators));

        // Domaine depuis infra.domains ET depuis DSL rules
        $this->assertStringContainsString('evil.com', $patternsStr);

        // Email depuis campaign.sender_emails
        $this->assertStringContainsString('phishing@evil.com', $patternsStr);

        // Domaine extrait depuis variants.url_shapes
        $this->assertStringContainsString('phishing.example.com', $patternsStr);

        // IP depuis infra.c2_servers
        $this->assertStringContainsString('203.0.113.1', $patternsStr);

        // Hash depuis malware.hashes
        $this->assertStringContainsString('d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2', $patternsStr);
    }
}
