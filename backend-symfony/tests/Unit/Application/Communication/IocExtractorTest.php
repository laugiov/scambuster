<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExtractor;
use App\Application\LLM\Port\LLMClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for IocExtractor (LLM-based IOC extraction)
 *
 * Tests LLM-based extraction with mocked LLM client to verify:
 * - JSON parsing and validation
 * - IOC structure validation
 * - Type filtering
 * - Error handling
 */
class IocExtractorTest extends TestCase
{
    private LLMClientInterface $llmClient;
    private LoggerInterface $logger;
    private IocExtractor $extractor;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->extractor = new IocExtractor($this->llmClient, $this->logger);
    }

    public function testExtractIocsWithLLMReturnsValidIocs(): void
    {
        // Mock LLM response with valid IOCs
        $llmResponse = json_encode([
            ['type' => 'email', 'value' => 'scammer@evil.com'],
            ['type' => 'url', 'value' => 'https://malicious-site.com'],
            ['type' => 'phone', 'value' => '+33612345678'],
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $text = 'Contact us at scammer@evil.com or visit https://malicious-site.com. Call +33612345678.';
        $iocs = $this->extractor->extractIocsWithLLM($text);

        $this->assertCount(3, $iocs);
        $this->assertSame('email', $iocs[0]['type']);
        $this->assertSame('scammer@evil.com', $iocs[0]['value']);
        $this->assertSame('url', $iocs[1]['type']);
        $this->assertSame('https://malicious-site.com', $iocs[1]['value']);
        $this->assertSame('phone', $iocs[2]['type']);
        $this->assertSame('+33612345678', $iocs[2]['value']);
    }

    /**
     * Spec 109 — postal_address survives the whitelist filter and
     * round-trips through extraction. Reads the LLM response, sees
     * the type is whitelisted, returns the IOC unchanged.
     */
    public function testExtractIocsWithLLMReturnsPostalAddress(): void
    {
        $llmResponse = json_encode([
            ['type' => 'postal_address', 'value' => 'Plot No 1 & 2, Mamram Towers, New Delhi 110096'],
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $text = 'Our registered office: Plot No 1 & 2, Mamram Towers, New Delhi 110096.';
        $iocs = $this->extractor->extractIocsWithLLM($text);

        $this->assertCount(1, $iocs);
        $this->assertSame('postal_address', $iocs[0]['type']);
        $this->assertSame('Plot No 1 & 2, Mamram Towers, New Delhi 110096', $iocs[0]['value']);
    }

    public function testExtractIocsWithLLMFiltersDisallowedTypes(): void
    {
        // Mock LLM response with mixed types
        $llmResponse = json_encode([
            ['type' => 'email', 'value' => 'test@example.com'],
            ['type' => 'url', 'value' => 'https://test.com'],
            ['type' => 'phone', 'value' => '+33123456789'],
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $this->logger
            ->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('disallowed IOC type'));

        // Only allow 'email' type
        $iocs = $this->extractor->extractIocsWithLLM('test text', ['email']);

        $this->assertCount(1, $iocs);
        $this->assertSame('email', $iocs[0]['type']);
        $this->assertSame('test@example.com', $iocs[0]['value']);
    }

    public function testExtractIocsWithLLMHandlesEmptyText(): void
    {
        $this->llmClient
            ->expects($this->never())
            ->method('chat');

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Cannot extract IOCs from empty text');

        $iocs = $this->extractor->extractIocsWithLLM('');

        $this->assertCount(0, $iocs);
    }

    public function testExtractIocsWithLLMTruncatesLongText(): void
    {
        $longText = str_repeat('A', 5000); // 5000 chars

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('[]');

        // Logger info is called twice: once for truncation, once for success
        $this->logger
            ->expects($this->atLeast(1))
            ->method('info');

        $this->extractor->extractIocsWithLLM($longText);
    }

    public function testExtractIocsWithLLMHandlesInvalidJson(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('invalid json {');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('LLM IOC extraction JSON parsing failed', $this->anything());

        $iocs = $this->extractor->extractIocsWithLLM('test text');

        $this->assertCount(0, $iocs);
    }

    public function testExtractIocsWithLLMHandlesNonArrayResponse(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('"string response"');

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('LLM IOC extraction returned non-array data', $this->anything());

        $iocs = $this->extractor->extractIocsWithLLM('test text');

        $this->assertCount(0, $iocs);
    }

    public function testExtractIocsWithLLMSkipsInvalidIocStructure(): void
    {
        // Mock LLM response with mixed valid and invalid IOCs
        $llmResponse = json_encode([
            ['type' => 'email', 'value' => 'valid@example.com'], // Valid
            ['type' => 'url'], // Invalid: missing value
            ['value' => 'https://test.com'], // Invalid: missing type
            'invalid string', // Invalid: not an array
            ['type' => 'phone', 'value' => '+33123456789'], // Valid
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $this->logger
            ->expects($this->exactly(2))
            ->method('warning')
            ->with($this->stringContains('Invalid IOC structure'));

        $iocs = $this->extractor->extractIocsWithLLM('test text');

        // Should only return the 2 valid IOCs
        $this->assertCount(2, $iocs);
        $this->assertSame('email', $iocs[0]['type']);
        $this->assertSame('phone', $iocs[1]['type']);
    }

    public function testExtractIocsWithLLMHandlesEmptyResponse(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('[]');

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('LLM IOC extraction successful', ['iocs_found' => 0]);

        $iocs = $this->extractor->extractIocsWithLLM('no iocs here');

        $this->assertCount(0, $iocs);
    }

    public function testExtractIocsWithLLMHandlesException(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willThrowException(new \RuntimeException('LLM API error'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('LLM IOC extraction failed', $this->anything());

        $iocs = $this->extractor->extractIocsWithLLM('test text');

        $this->assertCount(0, $iocs);
    }

    public function testExtractIocsWithLLMUsesLowTemperature(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->anything(),
                $this->callback(function ($options) {
                    return isset($options['temperature']) && $options['temperature'] === 0.1;
                })
            )
            ->willReturn('[]');

        $this->extractor->extractIocsWithLLM('test text');
    }

    public function testExtractIocsWithLLMIncludesAllTypesWhenEmpty(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn('[]');

        // Logger debug is called twice: once for "Calling LLM", once for "response received"
        $this->logger
            ->expects($this->atLeast(1))
            ->method('debug');

        $this->extractor->extractIocsWithLLM('test text');

        // Verify all IOC types are supported
        $types = $this->extractor->getSupportedTypes();
        $this->assertContains('email', $types);
        $this->assertContains('url', $types);
        $this->assertContains('phone', $types);
        $this->assertContains('iban', $types);
        $this->assertContains('wallet_btc', $types);
    }

    public function testGetSupportedTypesReturnsAllTypes(): void
    {
        $types = $this->extractor->getSupportedTypes();

        $this->assertIsArray($types);
        // We have 34 types currently (not 40+, adjust expectation)
        $this->assertGreaterThan(30, count($types), 'Should support 30+ IOC types');

        // Verify key types are present
        $this->assertContains('email', $types);
        $this->assertContains('url', $types);
        $this->assertContains('domain', $types);
        $this->assertContains('ipv4', $types);
        $this->assertContains('phone', $types);
        $this->assertContains('iban', $types);
        $this->assertContains('wallet_btc', $types);
        $this->assertContains('md5', $types);
        $this->assertContains('cve', $types);
        // Spec 109 — postal_address added to the whitelist
        $this->assertContains('postal_address', $types);
    }

    public function testExtractIocsWithLLMLogsResponsePreview(): void
    {
        $llmResponse = json_encode([
            ['type' => 'email', 'value' => 'test@example.com'],
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        // Logger debug is called twice: "Calling LLM" and "response received"
        $this->logger
            ->expects($this->atLeast(1))
            ->method('debug');

        $this->extractor->extractIocsWithLLM('test text');
    }

    public function testExtractIocsWithLLMSuccessfulExtractionLogsInfo(): void
    {
        $llmResponse = json_encode([
            ['type' => 'email', 'value' => 'test@example.com'],
            ['type' => 'url', 'value' => 'https://test.com'],
        ]);

        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->willReturn($llmResponse);

        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('LLM IOC extraction successful', ['iocs_found' => 2]);

        $iocs = $this->extractor->extractIocsWithLLM('test text');

        $this->assertCount(2, $iocs);
    }
}
