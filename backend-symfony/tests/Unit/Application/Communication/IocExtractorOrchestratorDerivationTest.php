<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExtractorOrchestrator;
use App\Application\Communication\IocNormalizer;
use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for IocExtractorOrchestrator::deriveAdditionalIocs and isPrivateIp.
 * The deriveAdditionalIocs method is public, so we test it directly.
 * isPrivateIp is exercised indirectly through extractIocsWithRegex (via Reflection).
 *
 * Targets uncovered lines: 261, 263-264, 267-272 (isPrivateIp branches).
 */
class IocExtractorOrchestratorDerivationTest extends TestCase
{
    private IocExtractorOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(IocExtractorOrchestrator::class);
        $this->orchestrator = $ref->newInstanceWithoutConstructor();

        // Set normalizer and validator via reflection
        $normalizer = new IocNormalizer();
        $validator = new IocValidator();

        $normProp = $ref->getProperty('normalizer');
        $normProp->setValue($this->orchestrator, $normalizer);

        $valProp = $ref->getProperty('validator');
        $valProp->setValue($this->orchestrator, $validator);
    }

    public function testDerivesDomainFromUrl(): void
    {
        $iocs = [
            [
                'type' => 'url',
                'value' => 'https://evil-phishing.com/login',
                'value_norm' => 'https://evil-phishing.com/login',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        $this->assertCount(2, $result); // original + derived domain
        $types = array_column($result, 'type');
        $this->assertContains('domain', $types);
    }

    public function testDerivesIpv4FromUrl(): void
    {
        $iocs = [
            [
                'type' => 'url',
                'value' => 'http://203.0.113.50/malware',
                'value_norm' => 'http://203.0.113.50/malware',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('ipv4', $types);
    }

    public function testDerivesDomainFromEmail(): void
    {
        $iocs = [
            [
                'type' => 'email',
                'value' => 'scammer@evil-domain.org',
                'value_norm' => 'scammer@evil-domain.org',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('domain', $types);
    }

    public function testSkipsCommonEmailDomains(): void
    {
        $iocs = [
            [
                'type' => 'email',
                'value' => 'user@gmail.com',
                'value_norm' => 'user@gmail.com',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        // gmail.com should be skipped
        $derivedTypes = [];
        foreach ($result as $ioc) {
            if (($ioc['context']['extraction_method'] ?? '') === 'derived_from_email') {
                $derivedTypes[] = $ioc['type'];
            }
        }
        $this->assertNotContains('domain', $derivedTypes);
    }

    public function testDoesNotDeriveDuplicates(): void
    {
        $iocs = [
            [
                'type' => 'url',
                'value' => 'https://evil.com/page1',
                'value_norm' => 'https://evil.com/page1',
            ],
            [
                'type' => 'url',
                'value' => 'https://evil.com/page2',
                'value_norm' => 'https://evil.com/page2',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        // Should only derive domain once
        $domains = array_filter($result, fn ($ioc) => $ioc['type'] === 'domain');
        $this->assertCount(1, $domains);
    }

    public function testIsPrivateIpFiltersTenNetwork(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        $this->assertTrue($method->invoke($this->orchestrator, '10.0.0.1'));
        $this->assertTrue($method->invoke($this->orchestrator, '10.255.255.255'));
    }

    public function testIsPrivateIpFiltersOneSeventyTwoNetwork(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        $this->assertTrue($method->invoke($this->orchestrator, '172.16.0.1'));
        $this->assertTrue($method->invoke($this->orchestrator, '172.31.255.255'));
        $this->assertFalse($method->invoke($this->orchestrator, '172.32.0.1'));
    }

    public function testIsPrivateIpFiltersOneNinetyTwoNetwork(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        $this->assertTrue($method->invoke($this->orchestrator, '192.168.0.1'));
        $this->assertTrue($method->invoke($this->orchestrator, '192.168.255.255'));
    }

    public function testIsPrivateIpFiltersLoopback(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        $this->assertTrue($method->invoke($this->orchestrator, '127.0.0.1'));
        $this->assertTrue($method->invoke($this->orchestrator, '127.255.255.255'));
    }

    public function testIsPrivateIpAllowsPublicIp(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        $this->assertFalse($method->invoke($this->orchestrator, '8.8.8.8'));
        $this->assertFalse($method->invoke($this->orchestrator, '203.0.113.1'));
    }

    public function testIsPrivateIpHandlesInvalidIp(): void
    {
        $method = new \ReflectionMethod(IocExtractorOrchestrator::class, 'isPrivateIp');

        // Invalid IP should return true (treated as private/filtered)
        $this->assertTrue($method->invoke($this->orchestrator, 'not-an-ip'));
    }

    public function testDerivesFromDefangedUrl(): void
    {
        $iocs = [
            [
                'type' => 'url',
                'value' => 'hxxps://evil[.]com/phish',
                'value_norm' => 'hxxps://evil[.]com/phish',
            ],
        ];

        $result = $this->orchestrator->deriveAdditionalIocs($iocs);

        $types = array_column($result, 'type');
        $this->assertContains('domain', $types);
    }
}
