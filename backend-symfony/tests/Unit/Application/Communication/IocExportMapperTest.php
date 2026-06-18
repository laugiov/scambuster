<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExportMapper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IocExportMapper.
 *
 * Tests MISP and STIX 2.1 metadata enrichment for all 12 IOC types.
 */
final class IocExportMapperTest extends TestCase
{
    private IocExportMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new IocExportMapper();
    }

    /**
     * @dataProvider provideIocTypes
     */
    public function testEnrichWithExportMetadataAddsMispAndStixForAllTypes(
        string $type,
        string $expectedMispType,
        string $expectedMispCategory,
        bool $expectedToIds,
        string $expectedStixScoType
    ): void {
        $iocContext = [
            'type' => $type,
            'value' => 'test-value',
            'value_norm' => 'test-value-norm',
        ];

        $enriched = $this->mapper->enrichWithExportMetadata($iocContext);

        // Assert original context is preserved
        $this->assertSame($type, $enriched['type']);
        $this->assertSame('test-value', $enriched['value']);
        $this->assertSame('test-value-norm', $enriched['value_norm']);

        // Assert MISP metadata
        $this->assertArrayHasKey('misp', $enriched);
        $this->assertSame($expectedMispCategory, $enriched['misp']['category']);
        $this->assertSame($expectedMispType, $enriched['misp']['type']);
        $this->assertSame($expectedToIds, $enriched['misp']['to_ids']);

        // Assert STIX metadata
        $this->assertArrayHasKey('stix', $enriched);
        $this->assertSame($expectedStixScoType, $enriched['stix']['sco_type']);
        $this->assertStringStartsWith('[', $enriched['stix']['pattern']);
        $this->assertStringEndsWith(']', $enriched['stix']['pattern']);
    }

    public function testStixPatternEscapesSingleQuotes(): void
    {
        $iocContext = [
            'type' => 'email',
            'value' => "test'value",
            'value_norm' => "test'value",
        ];

        $enriched = $this->mapper->enrichWithExportMetadata($iocContext);

        // Pattern should escape single quotes
        $this->assertStringContainsString("test\\'value", $enriched['stix']['pattern']);
        $this->assertStringNotContainsString("test'value", $enriched['stix']['pattern']);
    }

    public function testEnrichWithExportMetadataHandlesUnknownType(): void
    {
        $iocContext = [
            'type' => 'unknown_type',
            'value' => 'test',
            'value_norm' => 'test',
        ];

        $enriched = $this->mapper->enrichWithExportMetadata($iocContext);

        // Should fallback to 'Other' and 'other'
        $this->assertSame('Other', $enriched['misp']['category']);
        $this->assertSame('other', $enriched['misp']['type']);
        $this->assertFalse($enriched['misp']['to_ids']);

        // Should fallback to 'artifact'
        $this->assertSame('artifact', $enriched['stix']['sco_type']);
    }

    public function testEnrichWithExportMetadataHandlesMissingType(): void
    {
        $iocContext = [
            'value' => 'test',
            'value_norm' => 'test',
        ];

        $enriched = $this->mapper->enrichWithExportMetadata($iocContext);

        // Should fallback to defaults
        $this->assertArrayHasKey('misp', $enriched);
        $this->assertArrayHasKey('stix', $enriched);
    }

    /**
     * Data provider for all 12 supported IOC types.
     *
     * @return array<string, array{string, string, string, bool, string}>
     */
    public static function provideIocTypes(): array
    {
        return [
            'email' => [
                'email',
                'email-src',
                'Network activity',
                true,
                'email-addr',
            ],
            'url' => [
                'url',
                'url',
                'Network activity',
                true,
                'url',
            ],
            'domain' => [
                'domain',
                'domain',
                'Network activity',
                true,
                'domain-name',
            ],
            'ip' => [
                'ip',
                'ip-src',
                'Network activity',
                true,
                'ipv4-addr',
            ],
            'phone' => [
                'phone',
                'phone-number',
                'Person',
                false,
                'x-scambuster-phone',
            ],
            'iban' => [
                'iban',
                'iban',
                'Financial fraud',
                true,
                'x-scambuster-iban',
            ],
            'hash' => [
                'hash',
                'sha256',
                'Payload delivery',
                true,
                'file',
            ],
            'message_id' => [
                'message_id',
                'email-message-id',
                'Network activity',
                false,
                'email-message',
            ],
            'subject' => [
                'subject',
                'email-subject',
                'Network activity',
                false,
                'email-message',
            ],
            'spf_result' => [
                'spf_result',
                'email-header',
                'Network activity',
                false,
                'email-message',
            ],
            'dkim_result' => [
                'dkim_result',
                'dkim-signature',
                'Network activity',
                false,
                'email-message',
            ],
            'dmarc_result' => [
                'dmarc_result',
                'email-header',
                'Network activity',
                false,
                'email-message',
            ],
            // Spec 109 — postal_address: Person/other (MISP soft attr),
            // x-scambuster-postal-address (STIX custom SCO), to_ids=false
            // (pivot, not blocklist).
            'postal_address' => [
                'postal_address',
                'other',
                'Person',
                false,
                'x-scambuster-postal-address',
            ],
        ];
    }
}
