<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\STIXExporter;
use App\Application\Communication\IocExportMapper;
use App\Application\Stix\StixBundleBuilder;
use App\Domain\CampaignRadar\Campaign;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class STIXExporterTest extends TestCase
{
    private string $tmpPath;
    private STIXExporter $exporter;

    protected function setUp(): void
    {
        $this->tmpPath = sys_get_temp_dir() . '/stix-test-' . uniqid();
        mkdir($this->tmpPath);
        $builder = new StixBundleBuilder(new IocExportMapper());
        $this->exporter = new STIXExporter(new NullLogger(), $builder, $this->tmpPath);
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
        $campaign = new Campaign('test');
        $campaign->setProfileYaml("campaign:\n  summary: Test\nvariants:\n  url_shapes: []\ninfra:\n  domains: [\"evil.com\"]");

        $result = $this->exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $this->assertSame('bundle', $bundle['type']);
        // STIX 2.1: no spec_version on bundle
        $this->assertArrayNotHasKey('spec_version', $bundle);
        $this->assertArrayHasKey('objects', $bundle);
        $this->assertNotEmpty($bundle['objects']);
    }

    public function testExportFiltersOutPersonalEmails(): void
    {
        $campaign = new Campaign('test');
        $campaign->setProfileYaml("campaign:\n  summary: Test\nvariants: {}\ninfra:\n  domains: []\n  emails: [\"test@gmail.com\", \"contact@company.com\"]");

        $result = $this->exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = (string) file_get_contents($result['file_path']);

        $this->assertStringNotContainsString('gmail.com', $bundleJson);
        $this->assertStringContainsString('company.com', $bundleJson);
    }

    public function testExportHandlesEmptyProfile(): void
    {
        $campaign = new Campaign('test');

        $result = $this->exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Bundle should contain marking-definition, identity, report (no indicators)
        $types = array_column($bundle['objects'], 'type');
        $this->assertContains('marking-definition', $types);
        $this->assertContains('identity', $types);
        $this->assertContains('report', $types);
        $this->assertNotContains('indicator', $types);
    }

    public function testExportHandlesMalformedYaml(): void
    {
        $campaign = new Campaign('test');
        $campaign->setProfileYaml("invalid: yaml: [ unclosed");

        $result = $this->exporter->export($campaign);

        $this->assertFileExists($result['file_path']);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $this->assertArrayHasKey('objects', $bundle);
    }

    public function testExportGeneratesAllIoCTypes(): void
    {
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

        $result = $this->exporter->export($campaign);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $indicators = array_filter($bundle['objects'], fn ($obj) => $obj['type'] === 'indicator');
        $patterns = array_map(fn ($ind) => $ind['pattern'], $indicators);
        $patternsStr = implode('|', $patterns);

        $this->assertStringContainsString('domain-name:value', $patternsStr);
        $this->assertStringContainsString('evil.com', $patternsStr);
        $this->assertStringContainsString('email-addr:value', $patternsStr);
        $this->assertStringContainsString('scammer@evil.com', $patternsStr);
        $this->assertStringNotContainsString('gmail.com', $patternsStr);
        $this->assertStringContainsString('url:value', $patternsStr);
        // Phone uses OpenCTI-compatible type now
        $this->assertStringContainsString('x-opencti-phone-number:value', $patternsStr);
        $this->assertStringContainsString('ipv4-addr:value', $patternsStr);
        $this->assertStringContainsString("file:hashes.'SHA-256'", $patternsStr);

        $this->assertGreaterThanOrEqual(9, \count($indicators));
    }

    public function testExportIncludesTLPMarking(): void
    {
        $campaign = new Campaign('test');
        $campaign->setProfileYaml("campaign:\n  summary: Test\ninfra:\n  domains: [\"evil.com\"]");

        $result = $this->exporter->export($campaign);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        // Find marking-definition
        $marking = null;

        foreach ($bundle['objects'] as $obj) {
            if ($obj['type'] === 'marking-definition') {
                $marking = $obj;

                break;
            }
        }

        $this->assertNotNull($marking);
        $this->assertSame('tlp', $marking['definition_type']);
        $this->assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    public function testExportExtractsIoCsFromMultipleSources(): void
    {
        $campaign = new Campaign('test');

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

        $result = $this->exporter->export($campaign);

        $bundleJson = (string) file_get_contents($result['file_path']);
        $bundle = json_decode($bundleJson, true);

        $indicators = array_filter($bundle['objects'], fn ($obj) => $obj['type'] === 'indicator');
        $patternsStr = implode('|', array_map(fn ($ind) => $ind['pattern'], $indicators));

        $this->assertStringContainsString('evil.com', $patternsStr);
        $this->assertStringContainsString('phishing@evil.com', $patternsStr);
        $this->assertStringContainsString('phishing.example.com', $patternsStr);
        $this->assertStringContainsString('203.0.113.1', $patternsStr);
        $this->assertStringContainsString('d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2d2', $patternsStr);
    }
}
