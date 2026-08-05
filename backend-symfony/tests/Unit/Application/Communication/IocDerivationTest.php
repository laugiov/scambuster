<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExtractorOrchestrator;
use App\Application\Communication\IocNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IocExtractorOrchestrator::deriveAdditionalIocs().
 *
 * Migrated from IocHandler reflection tests after CT-0 decomposition.
 */
class IocDerivationTest extends TestCase
{
    private function callDerive(array $iocs): array
    {
        $orchestrator = (new \ReflectionClass(IocExtractorOrchestrator::class))
            ->newInstanceWithoutConstructor();

        // Inject normalizer via reflection (needed for domain normalization in derive)
        $normProp = new \ReflectionProperty(IocExtractorOrchestrator::class, 'normalizer');
        $normProp->setValue($orchestrator, new IocNormalizer());

        return $orchestrator->deriveAdditionalIocs($iocs);
    }

    public function testDerivesDomainFromUrl(): void
    {
        $iocs = [
            ['type' => 'url', 'value' => 'https://evil-test.com/path', 'value_norm' => 'hxxps://evil-test[.]com/path'],
        ];

        $result = $this->callDerive($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('domain', $types);

        $domainIoc = array_values(array_filter($result, fn ($i) => $i['type'] === 'domain'));
        $this->assertSame('evil-test.com', $domainIoc[0]['value']);
    }

    public function testDerivesIpFromUrlWithIpHost(): void
    {
        $iocs = [
            ['type' => 'url', 'value' => 'http://203.0.113.88/path', 'value_norm' => 'hxxp://203[.]0[.]113[.]88/path'],
        ];

        $result = $this->callDerive($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('ipv4', $types);
    }

    public function testDerivesDomainFromEmail(): void
    {
        $iocs = [
            ['type' => 'email', 'value' => 'scammer@evil-domain.com', 'value_norm' => 'scammer@evil-domain.com'],
        ];

        $result = $this->callDerive($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('domain', $types);

        $domainIoc = array_values(array_filter($result, fn ($i) => $i['type'] === 'domain'));
        $this->assertSame('evil-domain.com', $domainIoc[0]['value']);
    }

    public function testSkipsGmailDomain(): void
    {
        $iocs = [
            ['type' => 'email', 'value' => 'user@gmail.com', 'value_norm' => 'user@gmail.com'],
        ];

        $result = $this->callDerive($iocs);

        $types = array_column($result, 'type');
        $this->assertNotContains('domain', $types);
    }

    public function testSkipsProtonMeDomain(): void
    {
        $iocs = [
            ['type' => 'email', 'value' => 'user@proton.me', 'value_norm' => 'user@proton.me'],
        ];

        $result = $this->callDerive($iocs);

        $domains = array_filter($result, fn ($i) => $i['type'] === 'domain');
        $this->assertEmpty($domains);
    }

    public function testNoDuplicateDomains(): void
    {
        $iocs = [
            ['type' => 'url', 'value' => 'https://evil.com/page1', 'value_norm' => 'hxxps://evil[.]com/page1'],
            ['type' => 'url', 'value' => 'https://evil.com/page2', 'value_norm' => 'hxxps://evil[.]com/page2'],
            ['type' => 'email', 'value' => 'admin@evil.com', 'value_norm' => 'admin@evil.com'],
        ];

        $result = $this->callDerive($iocs);

        $domains = array_filter($result, fn ($i) => $i['type'] === 'domain');
        $this->assertCount(1, $domains);
    }

    public function testDoesNotDuplicateExistingDomain(): void
    {
        $iocs = [
            ['type' => 'url', 'value' => 'https://evil.com/path', 'value_norm' => 'hxxps://evil[.]com/path'],
            ['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com'],
        ];

        $result = $this->callDerive($iocs);

        $domains = array_filter($result, fn ($i) => $i['type'] === 'domain');
        $this->assertCount(1, $domains, 'Existing domain should not be duplicated');
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $result = $this->callDerive([]);
        $this->assertEmpty($result);
    }
}
