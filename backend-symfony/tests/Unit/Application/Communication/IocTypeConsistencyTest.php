<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExportMapper;
use App\Application\Communication\IocExtractor;
use App\Application\Communication\IocValidator;
use PHPUnit\Framework\TestCase;

/**
 * Enforces consistency across all IOC type registries.
 *
 * These tests prevent drift between ALL_IOC_TYPES (source of truth),
 * MISP_MAPPING, STIX_SCO_MAPPING, and IocValidator patterns.
 * If a new IOC type is added to one registry, these tests will fail
 * until all registries are updated.
 */
class IocTypeConsistencyTest extends TestCase
{
    /** @var string[] */
    private array $allIocTypes;

    /** @var string[] */
    private array $mispTypes;

    /** @var string[] */
    private array $stixTypes;

    /** @var string[] */
    private array $validatorTypes;

    protected function setUp(): void
    {
        $this->allIocTypes = IocExtractor::getSupportedTypes();

        $refMapper = new \ReflectionClass(IocExportMapper::class);
        $this->mispTypes = array_keys($refMapper->getConstant('MISP_MAPPING'));
        $this->stixTypes = array_keys($refMapper->getConstant('STIX_SCO_MAPPING'));

        $refValidator = new \ReflectionClass(IocValidator::class);
        $this->validatorTypes = array_keys($refValidator->getConstant('IOC_PATTERNS'));
    }

    public function testAllIocTypesCount(): void
    {
        // Bumped 35 → 36 with the addition of `postal_address`.
        $this->assertCount(36, $this->allIocTypes, 'ALL_IOC_TYPES must contain exactly 36 types');
    }

    public function testAllIocTypesHaveNoDuplicates(): void
    {
        $this->assertSame(
            count($this->allIocTypes),
            count(array_unique($this->allIocTypes)),
            'ALL_IOC_TYPES must not contain duplicates'
        );
    }

    public function testEveryIocTypeHasMispMapping(): void
    {
        $missing = array_diff($this->allIocTypes, $this->mispTypes);
        $this->assertEmpty(
            $missing,
            'IOC types missing from MISP_MAPPING: ' . implode(', ', $missing)
        );
    }

    public function testEveryIocTypeHasStixMapping(): void
    {
        $missing = array_diff($this->allIocTypes, $this->stixTypes);
        $this->assertEmpty(
            $missing,
            'IOC types missing from STIX_SCO_MAPPING: ' . implode(', ', $missing)
        );
    }

    public function testEveryIocTypeHasValidatorPattern(): void
    {
        $missing = array_diff($this->allIocTypes, $this->validatorTypes);
        $this->assertEmpty(
            $missing,
            'IOC types missing from IocValidator::IOC_PATTERNS: ' . implode(', ', $missing)
        );
    }

    public function testMispMappingOnlyContainsKnownTypes(): void
    {
        // Known aliases that are acceptable in mappings but not in ALL_IOC_TYPES
        $knownAliases = ['ip', 'hash', 'file_hash'];
        $extraTypes = array_diff($this->mispTypes, $this->allIocTypes, $knownAliases);

        $this->assertEmpty(
            $extraTypes,
            'MISP_MAPPING contains unknown types: ' . implode(', ', $extraTypes)
        );
    }

    public function testStixMappingOnlyContainsKnownTypes(): void
    {
        $knownAliases = ['ip', 'hash', 'file_hash'];
        $extraTypes = array_diff($this->stixTypes, $this->allIocTypes, $knownAliases);

        $this->assertEmpty(
            $extraTypes,
            'STIX_SCO_MAPPING contains unknown types: ' . implode(', ', $extraTypes)
        );
    }

    public function testMispMappingHasRequiredFields(): void
    {
        $refMapper = new \ReflectionClass(IocExportMapper::class);
        $mapping = $refMapper->getConstant('MISP_MAPPING');

        foreach ($mapping as $type => $attrs) {
            $this->assertArrayHasKey('category', $attrs, "MISP mapping for '{$type}' missing 'category'");
            $this->assertArrayHasKey('type', $attrs, "MISP mapping for '{$type}' missing 'type'");
            $this->assertArrayHasKey('to_ids', $attrs, "MISP mapping for '{$type}' missing 'to_ids'");
            $this->assertIsBool($attrs['to_ids'], "MISP mapping for '{$type}' 'to_ids' must be boolean");
        }
    }

    public function testStixMappingValuesAreNonEmpty(): void
    {
        $refMapper = new \ReflectionClass(IocExportMapper::class);
        $mapping = $refMapper->getConstant('STIX_SCO_MAPPING');

        foreach ($mapping as $type => $scoType) {
            $this->assertNotEmpty($scoType, "STIX SCO type for '{$type}' must not be empty");
            $this->assertIsString($scoType, "STIX SCO type for '{$type}' must be a string");
        }
    }

    public function testCustomStixTypesFollowNamingConvention(): void
    {
        $refMapper = new \ReflectionClass(IocExportMapper::class);
        $mapping = $refMapper->getConstant('STIX_SCO_MAPPING');

        foreach ($mapping as $type => $scoType) {
            if (str_starts_with($scoType, 'x-')) {
                $this->assertStringStartsWith(
                    'x-scambuster-',
                    $scoType,
                    "Custom STIX type for '{$type}' must use 'x-scambuster-' prefix, got '{$scoType}'"
                );
            }
        }
    }

    public function testFinancialIocTypesAreMarkedToIds(): void
    {
        $refMapper = new \ReflectionClass(IocExportMapper::class);
        $mapping = $refMapper->getConstant('MISP_MAPPING');

        $financialTypes = ['iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'bank_account', 'credit_card'];

        foreach ($financialTypes as $type) {
            $this->assertTrue(
                $mapping[$type]['to_ids'],
                "Financial IOC type '{$type}' must have to_ids=true in MISP mapping"
            );
        }
    }

    public function testIocExportMapperEnrichesAllTypes(): void
    {
        $mapper = new IocExportMapper();

        foreach ($this->allIocTypes as $type) {
            $context = ['type' => $type, 'value_norm' => 'test-value'];
            $enriched = $mapper->enrichWithExportMetadata($context);

            $this->assertArrayHasKey('misp', $enriched, "enrichWithExportMetadata missing 'misp' for type '{$type}'");
            $this->assertArrayHasKey('stix', $enriched, "enrichWithExportMetadata missing 'stix' for type '{$type}'");
            $this->assertArrayHasKey('sco_type', $enriched['stix'], "STIX metadata missing 'sco_type' for type '{$type}'");
            $this->assertArrayHasKey('pattern', $enriched['stix'], "STIX metadata missing 'pattern' for type '{$type}'");

            // Ensure no type falls back to 'Other' category (which means it's unmapped)
            $this->assertNotSame(
                'Other',
                $enriched['misp']['category'],
                "IOC type '{$type}' falls back to 'Other' in MISP -- add explicit mapping"
            );

            // Ensure no type falls back to 'artifact' (which means it's unmapped)
            $this->assertNotSame(
                'artifact',
                $enriched['stix']['sco_type'],
                "IOC type '{$type}' falls back to 'artifact' in STIX -- add explicit mapping"
            );
        }
    }
}
