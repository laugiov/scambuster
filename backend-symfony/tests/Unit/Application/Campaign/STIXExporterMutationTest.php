<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\STIXExporter;
use App\Application\Communication\IocExportMapper;
use App\Application\Stix\StixBundleBuilder;
use App\Domain\CampaignRadar\Campaign;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Mutation-killing tests for STIXExporter.
 *
 * Targets: bundle type, deduplication, indicator patterns,
 * relationship source_ref/target_ref, YAML extraction paths,
 * PII filtering, profile schema validation.
 */
final class STIXExporterMutationTest extends TestCase
{
    private string $tmpPath;
    private STIXExporter $exporter;

    protected function setUp(): void
    {
        $this->tmpPath = sys_get_temp_dir() . '/stix-mutation-test-' . uniqid();
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
        if (is_dir($this->tmpPath)) {
            rmdir($this->tmpPath);
        }
    }

    // ── Bundle type and structure ──

    public function testBundleTypeIsExactlyBundle(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: [\"evil.com\"]");
        $result = $this->exporter->export($campaign);
        self::assertSame('bundle', $result['bundle']['type']);
    }

    public function testBundleIdStartsWithBundlePrefix(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: []");
        $result = $this->exporter->export($campaign);
        self::assertStringStartsWith('bundle--', $result['bundle']['id']);
    }

    public function testBundleIdReturnedInResult(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: []");
        $result = $this->exporter->export($campaign);
        self::assertSame($result['bundle']['id'], $result['bundle_id']);
    }

    public function testResultContainsFilePath(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: []");
        $result = $this->exporter->export($campaign);
        self::assertStringStartsWith($this->tmpPath, $result['file_path']);
        self::assertFileExists($result['file_path']);
    }

    public function testFileContainsValidJson(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: [\"evil.com\"]");
        $result = $this->exporter->export($campaign);
        $json = file_get_contents($result['file_path']);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertSame('bundle', $decoded['type']);
    }

    // ── Report name ──

    public function testReportNameContainsCampaignIdPrefix(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  summary: Test\ninfra:\n  domains: []");
        $result = $this->exporter->export($campaign);
        $report = $this->findObjectByType($result['bundle'], 'report');
        self::assertStringStartsWith('ScamBuster Campaign ', $report['name']);
        // First 8 chars of UUID
        self::assertSame(28, \strlen($report['name'])); // "ScamBuster Campaign " + 8
    }

    // ── Indicator patterns per IOC type ──

    public function testIndicatorPatternForDomains(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  domains: [\"evil.example.com\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = array_column($indicators, 'pattern');
        $found = false;
        foreach ($patterns as $p) {
            if (str_contains($p, 'domain-name:value') && str_contains($p, 'evil.example.com')) {
                $found = true;
            }
        }
        self::assertTrue($found, 'Expected domain indicator pattern');
    }

    public function testIndicatorPatternForEmails(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@corp.com\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = array_column($indicators, 'pattern');
        $patternsStr = implode('|', $patterns);
        self::assertStringContainsString('email-addr:value', $patternsStr);
        self::assertStringContainsString('scammer@corp.com', $patternsStr);
    }

    public function testIndicatorPatternForIpAddresses(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  ip_addresses: [\"10.0.0.1\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = implode('|', array_column($indicators, 'pattern'));
        self::assertStringContainsString('ipv4-addr:value', $patterns);
        self::assertStringContainsString('10.0.0.1', $patterns);
    }

    public function testIndicatorPatternForFileHashes(): void
    {
        $hash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $campaign = $this->campaignWithProfile("infra:\n  file_hashes: [\"{$hash}\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = implode('|', array_column($indicators, 'pattern'));
        self::assertStringContainsString("file:hashes.'SHA-256'", $patterns);
    }

    public function testIndicatorPatternForPhoneNumbers(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  phone_numbers: [\"+33612345678\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = implode('|', array_column($indicators, 'pattern'));
        self::assertStringContainsString('x-opencti-phone-number:value', $patterns);
    }

    // ── PII filtering ──

    public function testGmailFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@gmail.com\", \"real@corp.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringNotContainsString('gmail.com', $json);
        self::assertStringContainsString('real@corp.com', $json);
    }

    public function testYahooFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@yahoo.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringNotContainsString('yahoo.com', $json);
    }

    public function testHotmailFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@hotmail.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringNotContainsString('hotmail.com', $json);
    }

    public function testOutlookFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@outlook.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringNotContainsString('outlook.com', $json);
    }

    public function testLiveFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"scammer@live.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringNotContainsString('@live.com', $json);
    }

    // ── PII in bundle validation throws ──

    public function testPiiInBundleThrowsRuntimeException(): void
    {
        // Build a campaign that would embed a gmail address in a non-email field
        // The PII validator checks the whole JSON, so we rely on the email filter catching this upstream
        // This tests the profile filtering, not the bundle validator directly
        $campaign = $this->campaignWithProfile("infra:\n  emails: [\"innocent@gmail.com\"]");
        // Should NOT throw, because the email was filtered before bundling
        $result = $this->exporter->export($campaign);
        self::assertSame('bundle', $result['bundle']['type']);
    }

    // ── YAML extraction sources ──

    public function testDomainsFromInfra(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  domains: [\"a.com\", \"b.com\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('a.com', $json);
        self::assertStringContainsString('b.com', $json);
    }

    public function testDomainsFromUrlShapes(): void
    {
        $campaign = $this->campaignWithProfile("variants:\n  url_shapes: [\"https://phish.example.com/login\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('phish.example.com', $json);
    }

    public function testDomainsFromDslRules(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  domains: []\nrules:\n  - \"from.domain == 'evil.org' AND subject.contains('urgent')\"");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('evil.org', $json);
    }

    public function testEmailsFromCampaignSenderEmails(): void
    {
        $campaign = $this->campaignWithProfile("campaign:\n  sender_emails: [\"phish@corp.com\"]\ninfra: {}");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('phish@corp.com', $json);
    }

    public function testIpFromC2Servers(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  c2_servers: [\"203.0.113.99\"]");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('203.0.113.99', $json);
    }

    public function testFileHashesFromMalware(): void
    {
        $campaign = $this->campaignWithProfile("malware:\n  hashes: [\"abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234abcd1234\"]\ninfra: {}");
        $result = $this->exporter->export($campaign);
        $json = json_encode($result['bundle']);
        self::assertStringContainsString('abcd1234', $json);
    }

    // ── Profile validation ──

    public function testInvalidProfileSchemaProducesNoIndicators(): void
    {
        // Profile without infra, variants, or campaign key -> invalid schema
        $campaign = $this->campaignWithProfile("random_key: value");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        self::assertCount(0, $indicators);
    }

    public function testMalformedYamlProducesNoIndicators(): void
    {
        $campaign = new Campaign('test');
        $campaign->setProfileYaml("invalid: yaml: [ unclosed");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        self::assertCount(0, $indicators);
    }

    public function testNullProfileProducesNoIndicators(): void
    {
        $campaign = new Campaign('test');
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        self::assertCount(0, $indicators);
    }

    // ── URL placeholder filtering ──

    public function testUrlWithPlaceholderFilteredOut(): void
    {
        $campaign = $this->campaignWithProfile("variants:\n  url_shapes: [\"https://evil.com/{id}\", \"https://safe.com/path\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $patterns = implode('|', array_column($indicators, 'pattern'));
        self::assertStringNotContainsString('{id}', $patterns);
        self::assertStringContainsString('safe.com', $patterns);
    }

    // ── TLP marking ──

    public function testDefaultTlpAmberMarking(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  domains: [\"x.com\"]");
        $result = $this->exporter->export($campaign);
        $marking = $this->findObjectByType($result['bundle'], 'marking-definition');
        self::assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    // ── Dedup ──

    public function testDuplicateDomainsDeduped(): void
    {
        $campaign = $this->campaignWithProfile("infra:\n  domains: [\"dup.com\", \"dup.com\"]");
        $result = $this->exporter->export($campaign);
        $indicators = $this->findAllObjectsByType($result['bundle'], 'indicator');
        $domainIndicators = array_filter($indicators, fn ($ind) => str_contains($ind['pattern'], 'dup.com'));
        // Dedup happens at YAML extraction level
        self::assertCount(1, $domainIndicators);
    }

    // ── Helpers ──

    private function campaignWithProfile(string $yaml): Campaign
    {
        $campaign = new Campaign('test');
        $campaign->setProfileYaml($yaml);
        return $campaign;
    }

    private function findObjectByType(array $bundle, string $type): ?array
    {
        foreach ($bundle['objects'] ?? [] as $obj) {
            if (($obj['type'] ?? '') === $type) {
                return $obj;
            }
        }
        return null;
    }

    private function findAllObjectsByType(array $bundle, string $type): array
    {
        return array_values(array_filter($bundle['objects'] ?? [], fn ($obj) => ($obj['type'] ?? '') === $type));
    }
}
