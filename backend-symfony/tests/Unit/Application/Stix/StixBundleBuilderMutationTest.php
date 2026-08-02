<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Stix;

use App\Application\Communication\IocExportMapper;
use App\Application\Stix\StixBundleBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-killing tests for StixBundleBuilder.
 *
 * Each test targets specific Infection mutant patterns:
 * string concatenation, return value, method call removal,
 * logical operators, array key changes.
 */
final class StixBundleBuilderMutationTest extends TestCase
{
    private StixBundleBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new StixBundleBuilder(new IocExportMapper());
    }

    // ── Bundle structure ──

    public function testBundleTypeIsExactlyBundle(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertSame('bundle', $bundle['type']);
    }

    public function testBundleIdStartsWithBundlePrefix(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertStringStartsWith('bundle--', $bundle['id']);
        self::assertSame(44, \strlen($bundle['id']), 'bundle id = "bundle--" (8) + UUID (36) = 44 chars');
    }

    public function testBundleHasObjectsArray(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertIsArray($bundle['objects']);
        self::assertNotEmpty($bundle['objects']);
    }

    public function testBundleHasNoSpecVersion(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertArrayNotHasKey('spec_version', $bundle);
    }

    public function testBundleHasExactlyThreeKeys(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertCount(3, $bundle);
        self::assertArrayHasKey('type', $bundle);
        self::assertArrayHasKey('id', $bundle);
        self::assertArrayHasKey('objects', $bundle);
    }

    // ── Marking definition ──

    public function testMarkingDefinitionSpecVersionIs21(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('2.1', $marking['spec_version']);
    }

    public function testMarkingDefinitionTypeFieldExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition', $marking['type']);
        self::assertSame('tlp', $marking['definition_type']);
    }

    public function testMarkingAmberIdExact(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    public function testMarkingGreenIdExact(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'GREEN');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da', $marking['id']);
    }

    public function testMarkingRedIdExact(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'RED');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--5e57c739-391a-4eb3-b6be-7d15ca92d5ed', $marking['id']);
    }

    public function testMarkingWhiteNormalizedToClear(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'WHITE');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('TLP:CLEAR', $marking['name']);
        self::assertSame('clear', $marking['definition']['tlp']);
    }

    public function testMarkingAmberNameExact(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('TLP:AMBER', $marking['name']);
        self::assertSame('amber', $marking['definition']['tlp']);
    }

    public function testMarkingCreatedTimestampExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('2017-01-20T00:00:00.000Z', $marking['created']);
    }

    public function testTlpPrefixStrippingTlpColon(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'TLP:AMBER');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    public function testTlpPrefixStrippingTlpUnderscore(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'TLP_RED');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--5e57c739-391a-4eb3-b6be-7d15ca92d5ed', $marking['id']);
    }

    public function testUnknownTlpDefaultsToAmber(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'PURPLE');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    // ── Identity ──

    public function testIdentitySpecVersion21(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('2.1', $identity['spec_version']);
    }

    public function testIdentityTypeExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('identity', $identity['type']);
    }

    public function testIdentityIdDeterministic(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $identity['id']);
    }

    public function testIdentityNameExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('ScamBuster Threat Intelligence', $identity['name']);
    }

    public function testIdentityClassSystem(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('system', $identity['identity_class']);
    }

    public function testIdentityCreatedTimestamp(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame('2025-12-01T00:00:00.000Z', $identity['created']);
        self::assertSame('2025-12-01T00:00:00.000Z', $identity['modified']);
    }

    public function testIdentityHasMarkingRefs(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER');
        $identity = $this->findObject($bundle, 'identity');
        self::assertSame(['marking-definition--f88d31f6-486f-44da-b317-01333bde0b82'], $identity['object_marking_refs']);
    }

    // ── Extension definitions ──

    public function testExtensionDefinitionsPresent(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $extDefs = $this->findAllObjects($bundle, 'extension-definition');
        // context + actor + psych (all custom extensions now have a referenced ext-def).
        self::assertCount(3, $extDefs);
    }

    public function testExtensionDefinitionContextId(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $extDefs = $this->findAllObjects($bundle, 'extension-definition');
        $ids = array_column($extDefs, 'id');
        self::assertContains('extension-definition--b2a37c23-41d7-4e2f-9c8a-1a5f6d3e8b90', $ids);
        self::assertContains('extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01', $ids);
        self::assertContains(\App\Application\Stix\ScambusterStixExtensions::PSYCH_ID, $ids);
    }

    public function testExtensionDefinitionFieldsExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $extDefs = $this->findAllObjects($bundle, 'extension-definition');
        foreach ($extDefs as $ext) {
            self::assertSame('2.1', $ext['spec_version']);
            self::assertSame('extension-definition', $ext['type']);
            self::assertSame('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $ext['created_by_ref']);
            self::assertSame('https://github.com/laugiov/scambuster', $ext['schema']);
            self::assertSame('1.0', $ext['version']);
            self::assertSame(['property-extension'], $ext['extension_types']);
        }
    }

    public function testExtensionDefinitionTimestamps(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $extDefs = $this->findAllObjects($bundle, 'extension-definition');
        foreach ($extDefs as $ext) {
            self::assertSame('2025-12-01T00:00:00.000Z', $ext['created']);
            self::assertSame('2026-04-07T00:00:00.000Z', $ext['modified']);
        }
    }

    // ── Indicator fields ──

    public function testIndicatorTypeExact(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('indicator', $ind['type']);
    }

    public function testIndicatorSpecVersion21(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('2.1', $ind['spec_version']);
    }

    public function testIndicatorIdPrefix(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertStringStartsWith('indicator--', $ind['id']);
    }

    public function testIndicatorIdDeterministicOnTypeAndValue(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-01-01']];
        $b1 = $this->builder->buildBundle($iocs);
        $b2 = $this->builder->buildBundle($iocs);
        $ind1 = $this->findObject($b1, 'indicator');
        $ind2 = $this->findObject($b2, 'indicator');
        self::assertSame($ind1['id'], $ind2['id']);
    }

    public function testIndicatorPatternTypeStix(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('stix', $ind['pattern_type']);
        self::assertSame('2.1', $ind['pattern_version']);
    }

    public function testIndicatorCreatedByRefIsIdentity(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $ind['created_by_ref']);
    }

    public function testIndicatorLabelsIncludesMaliciousActivity(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertContains('malicious-activity', $ind['labels']);
        self::assertSame(['malicious-activity'], $ind['indicator_types']);
    }

    public function testIndicatorLabelsIncludesScamType(): void
    {
        $iocs = [['type' => 'email', 'value' => 'x@evil.com', 'value_norm' => 'x@evil.com', 'first_seen' => '2026-01-01', 'scam_type' => 'PHISHING']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertContains('malicious-activity', $ind['labels']);
        self::assertContains('phishing', $ind['labels']);
    }

    public function testIndicatorConfidenceFromFloatValue(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01', 'confidence' => 0.85]];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame(85, $ind['confidence']);
    }

    public function testIndicatorConfidenceFromExtractionMethod(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01', 'extraction_method' => 'regex']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame(95, $ind['confidence']);
    }

    public function testIndicatorConfidenceDefault80(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame(80, $ind['confidence']);
    }

    public function testIndicatorConfidenceClampedTo0And100(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01', 'confidence' => 1.5]];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertLessThanOrEqual(100, $ind['confidence']);
        self::assertGreaterThanOrEqual(0, $ind['confidence']);
    }

    public function testIndicatorConfidenceIsInteger(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertIsInt($ind['confidence']);
    }

    public function testIndicatorTimestampsIso8601WithMs(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $ind = $this->findObject($bundle, 'indicator');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $ind['valid_from']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $ind['created']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $ind['modified']);
    }

    public function testIndicatorNameFormatUppercaseTypeColonValue(): void
    {
        $iocs = [['type' => 'email', 'value' => 'scammer@evil.com', 'value_norm' => 'scammer@evil.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('EMAIL: scammer@evil.com', $ind['name']);
    }

    public function testIndicatorNameTruncatesLongValues(): void
    {
        $longVal = str_repeat('a', 100);
        $iocs = [['type' => 'url', 'value' => $longVal, 'value_norm' => $longVal, 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertStringEndsWith('...', $ind['name']);
        self::assertLessThanOrEqual(85, mb_strlen($ind['name']));
    }

    public function testIndicatorMarkingRefsMatchTlp(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs, [], 'GREEN');
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame(['marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da'], $ind['object_marking_refs']);
    }

    public function testIndicatorExternalReferences(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-01-01', 'indicator_id' => 'my-custom-id']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('ScamBuster', $ind['external_references'][0]['source_name']);
        self::assertSame('my-custom-id', $ind['external_references'][0]['external_id']);
    }

    public function testIndicatorExternalReferencesFallback(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('ScamBuster', $ind['external_references'][0]['source_name']);
        self::assertStringStartsWith('indicator--', $ind['external_references'][0]['external_id']);
    }

    // ── Indicator OpenCTI extension ──

    public function testOpenCtiExtensionScoreFromAgg(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01', 'score' => ['agg' => 72]]];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame(72, $ext['x_opencti_score']);
    }

    public function testOpenCtiExtensionScoreFallsBackToConfidence(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame(80, $ext['x_opencti_score']);
    }

    public function testOpenCtiExtensionObservableTypeEmail(): void
    {
        $iocs = [['type' => 'email', 'value' => 'a@b.com', 'value_norm' => 'a@b.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('Email-Addr', $ext['x_opencti_main_observable_type']);
    }

    public function testOpenCtiExtensionObservableTypeIpv4(): void
    {
        $iocs = [['type' => 'ipv4', 'value' => '1.2.3.4', 'value_norm' => '1.2.3.4', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('IPv4-Addr', $ext['x_opencti_main_observable_type']);
    }

    public function testOpenCtiExtensionObservableTypeSha256(): void
    {
        $iocs = [['type' => 'sha256', 'value' => 'abc123', 'value_norm' => 'abc123', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('StixFile', $ext['x_opencti_main_observable_type']);
    }

    public function testOpenCtiExtensionObservableTypePhone(): void
    {
        $iocs = [['type' => 'phone', 'value' => '+1234', 'value_norm' => '+1234', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('Phone-Number', $ext['x_opencti_main_observable_type']);
    }

    public function testOpenCtiExtensionObservableTypeIban(): void
    {
        $iocs = [['type' => 'iban', 'value' => 'DE89370400440532013000', 'value_norm' => 'de89370400440532013000', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('Bank-Account', $ext['x_opencti_main_observable_type']);
    }

    public function testOpenCtiExtensionObservableTypeCryptoWallet(): void
    {
        $iocs = [['type' => 'wallet_btc', 'value' => '1A1zP1', 'value_norm' => '1a1zp1', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        $ext = $ind['extensions']['extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba'];
        self::assertSame('Cryptocurrency-Wallet', $ext['x_opencti_main_observable_type']);
    }

    // ── Indicator patterns ──

    public function testPatternForDomain(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[domain-name:value = 'evil.com']", $ind['pattern']);
    }

    public function testPatternForEmail(): void
    {
        $iocs = [['type' => 'email', 'value' => 'a@b.com', 'value_norm' => 'a@b.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[email-addr:value = 'a@b.com']", $ind['pattern']);
    }

    public function testPatternForSha256(): void
    {
        $hash = 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890';
        $iocs = [['type' => 'sha256', 'value' => $hash, 'value_norm' => $hash, 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[file:hashes.'SHA-256' = '{$hash}']", $ind['pattern']);
    }

    public function testPatternForSha1(): void
    {
        $hash = 'da39a3ee5e6b4b0d3255bfef95601890afd80709';
        $iocs = [['type' => 'sha1', 'value' => $hash, 'value_norm' => $hash, 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[file:hashes.'SHA-1' = '{$hash}']", $ind['pattern']);
    }

    public function testPatternForMd5(): void
    {
        $hash = 'd41d8cd98f00b204e9800998ecf8427e';
        $iocs = [['type' => 'md5', 'value' => $hash, 'value_norm' => $hash, 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[file:hashes.'MD5' = '{$hash}']", $ind['pattern']);
    }

    public function testPatternForPhone(): void
    {
        $iocs = [['type' => 'phone', 'value' => '+33612345678', 'value_norm' => '+33612345678', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[x-opencti-phone-number:value = '+33612345678']", $ind['pattern']);
    }

    public function testPatternForIban(): void
    {
        $iocs = [['type' => 'iban', 'value' => 'DE89370400440532013000', 'value_norm' => 'de89370400440532013000', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[x-opencti-bank-account:value = 'de89370400440532013000']", $ind['pattern']);
    }

    public function testPatternForWalletBtc(): void
    {
        $iocs = [['type' => 'wallet_btc', 'value' => '1A1zP1', 'value_norm' => '1a1zp1', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[x-opencti-cryptocurrency-wallet:value = '1a1zp1']", $ind['pattern']);
    }

    public function testPatternEscapesSingleQuotes(): void
    {
        $iocs = [['type' => 'domain', 'value' => "it's.com", 'value_norm' => "it's.com", 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertStringContainsString("\\'", $ind['pattern']);
    }

    // ── Null/skip indicator cases ──

    public function testIndicatorNullWhenEmptyType(): void
    {
        $iocs = [['type' => '', 'value' => 'x', 'value_norm' => 'x', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertNull($ind);
    }

    public function testIndicatorNullWhenEmptyValue(): void
    {
        $iocs = [['type' => 'domain', 'value' => '', 'value_norm' => '', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertNull($ind);
    }

    /**
     * @dataProvider headerTypeProvider
     */
    public function testHeaderTypesSkipped(string $headerType): void
    {
        $iocs = [['type' => $headerType, 'value' => 'something', 'value_norm' => 'something', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertNull($ind);
    }

    public static function headerTypeProvider(): array
    {
        return [
            ['message_id'],
            ['subject'],
            ['spf_result'],
            ['dkim_result'],
            ['dmarc_result'],
            ['x_mailer'],
            ['return_path'],
        ];
    }

    // ── Relationship ──

    public function testRelationshipTypeExact(): void
    {
        $bundle = $this->buildWithRelationship();
        $rel = $this->findObject($bundle, 'relationship');
        self::assertSame('relationship', $rel['type']);
        self::assertSame('related-to', $rel['relationship_type']);
    }

    public function testRelationshipSpecVersion21(): void
    {
        $bundle = $this->buildWithRelationship();
        $rel = $this->findObject($bundle, 'relationship');
        self::assertSame('2.1', $rel['spec_version']);
    }

    public function testRelationshipIdPrefix(): void
    {
        $bundle = $this->buildWithRelationship();
        $rel = $this->findObject($bundle, 'relationship');
        self::assertStringStartsWith('relationship--', $rel['id']);
    }

    public function testRelationshipSourceAndTargetRefs(): void
    {
        $bundle = $this->buildWithRelationship();
        $rel = $this->findObject($bundle, 'relationship');
        self::assertStringStartsWith('indicator--', $rel['source_ref']);
        self::assertStringStartsWith('indicator--', $rel['target_ref']);
        self::assertNotSame($rel['source_ref'], $rel['target_ref']);
    }

    public function testRelationshipConfidenceCalculation(): void
    {
        $rels = [[
            'source_indicator_id' => 'a', 'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
            'weight' => 3,
        ]];
        $bundle = $this->builder->buildBundle([], $rels);
        $rel = $this->findObject($bundle, 'relationship');
        // 50 + 3*10 = 80
        self::assertSame(80, $rel['confidence']);
    }

    public function testRelationshipConfidenceCappedAt95(): void
    {
        $rels = [[
            'source_indicator_id' => 'a', 'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
            'weight' => 100,
        ]];
        $bundle = $this->builder->buildBundle([], $rels);
        $rel = $this->findObject($bundle, 'relationship');
        self::assertSame(95, $rel['confidence']);
    }

    public function testRelationshipDescriptionContainsWeight(): void
    {
        $rels = [[
            'source_indicator_id' => 'a', 'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
            'weight' => 5,
        ]];
        $bundle = $this->builder->buildBundle([], $rels);
        $rel = $this->findObject($bundle, 'relationship');
        self::assertSame('Co-observed in 5 conversation(s)', $rel['description']);
    }

    public function testRelationshipNullWhenMissingSourceId(): void
    {
        $rels = [[
            'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
        ]];
        $bundle = $this->builder->buildBundle([], $rels);
        $rel = $this->findObject($bundle, 'relationship');
        self::assertNull($rel);
    }

    public function testRelationshipNullWhenMissingTargetId(): void
    {
        $rels = [[
            'source_indicator_id' => 'a',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
        ]];
        $bundle = $this->builder->buildBundle([], $rels);
        $rel = $this->findObject($bundle, 'relationship');
        self::assertNull($rel);
    }

    public function testRelationshipsMaxCap100(): void
    {
        $rels = [];
        for ($i = 0; $i < 110; ++$i) {
            $rels[] = [
                'source_indicator_id' => "src-{$i}", 'target_indicator_id' => "tgt-{$i}",
                'source_type' => 'domain', 'source_value_norm' => "d{$i}.com",
                'target_type' => 'email', 'target_value_norm' => "a{$i}@b.com",
                'weight' => 1,
            ];
        }
        $bundle = $this->builder->buildBundle([], $rels);
        $allRels = $this->findAllObjects($bundle, 'relationship');
        self::assertCount(100, $allRels);
    }

    // ── Report ──

    public function testReportTypeAndSpecVersion(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('report', $report['type']);
        self::assertSame('2.1', $report['spec_version']);
    }

    public function testReportIdPrefix(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertStringStartsWith('report--', $report['id']);
    }

    public function testReportNameFromParameter(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER', 'Custom Report Name');
        $report = $this->findObject($bundle, 'report');
        self::assertSame('Custom Report Name', $report['name']);
    }

    public function testReportDefaultName(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('ScamBuster IOC Export', $report['name']);
    }

    public function testReportDescriptionFromParameter(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER', 'R', 'Custom description');
        $report = $this->findObject($bundle, 'report');
        self::assertSame('Custom description', $report['description']);
    }

    public function testReportDefaultDescription(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('Threat intelligence collected by ScamBuster automated honeypot', $report['description']);
    }

    public function testReportLabelsExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame(['threat-report', 'scam'], $report['labels']);
    }

    public function testReportObjectRefsIncludeIdentity(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertContains('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $report['object_refs']);
    }

    public function testReportObjectRefsIncludeIndicators(): void
    {
        $bundle = $this->buildWithDomainIoc();
        $report = $this->findObject($bundle, 'report');
        $ind = $this->findObject($bundle, 'indicator');
        self::assertContains($ind['id'], $report['object_refs']);
    }

    public function testReportTimestampsIso8601(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $report['created']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $report['published']);
    }

    // ── No duplicate IDs ──

    public function testBundleHasNoDuplicateIds(): void
    {
        $iocs = [
            ['type' => 'domain', 'value' => 'a.com', 'value_norm' => 'a.com', 'first_seen' => '2026-01-01'],
            ['type' => 'email', 'value' => 'x@b.com', 'value_norm' => 'x@b.com', 'first_seen' => '2026-01-01'],
        ];
        $rels = [[
            'source_indicator_id' => 'a', 'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'a.com',
            'target_type' => 'email', 'target_value_norm' => 'x@b.com',
            'weight' => 1,
        ]];
        $bundle = $this->builder->buildBundle($iocs, $rels);
        $ids = array_column($bundle['objects'], 'id');
        self::assertSame($ids, array_unique($ids), 'Bundle should have no duplicate object IDs');
    }

    // ── Empty inputs ──

    public function testEmptyIocsProducesValidBundle(): void
    {
        $bundle = $this->builder->buildBundle([]);
        self::assertSame('bundle', $bundle['type']);
        $types = array_column($bundle['objects'], 'type');
        self::assertContains('marking-definition', $types);
        self::assertContains('identity', $types);
        self::assertContains('report', $types);
        self::assertContains('extension-definition', $types);
        self::assertNotContains('indicator', $types);
    }

    // ── Context extension ──

    public function testContextExtensionAddedWhenContextPresent(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01',
            'context' => ['enrichment_status' => 'enriched', 'scam_type_code' => 'PHISHING', 'semantic_role' => 'infrastructure']]];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');

        // STIX 2.1 conformance: the custom extension MUST be keyed by the
        // extension-definition id, carry extension_type = property-extension, and
        // NOT sit under a bare x_... key (which a strict validator rejects).
        self::assertArrayHasKey(\App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID, $ind['extensions']);
        self::assertArrayNotHasKey('x_scambuster_context', $ind['extensions']);
        $ext = $ind['extensions'][\App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID];
        self::assertSame('property-extension', $ext['extension_type']);
        self::assertArrayHasKey('x_scambuster_context', $ext);
        self::assertSame('PHISHING', $ext['x_scambuster_context']['scam_type']);
    }

    public function testContextExtensionSkippedWhenPending(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01',
            'context' => ['enrichment_status' => 'pending']]];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertArrayNotHasKey(\App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID, $ind['extensions'] ?? []);
    }

    // ── valid_until ──

    public function testValidUntilForDomain30Days(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'x.com', 'value_norm' => 'x.com', 'first_seen' => '2026-01-01 00:00:00']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('2026-01-31T00:00:00.000Z', $ind['valid_until']);
    }

    public function testValidUntilForIpv47Days(): void
    {
        $iocs = [['type' => 'ipv4', 'value' => '1.2.3.4', 'value_norm' => '1.2.3.4', 'first_seen' => '2026-04-01 00:00:00']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame('2026-04-08T00:00:00.000Z', $ind['valid_until']);
    }

    // ── Helpers ──

    private function buildWithDomainIoc(): array
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-04-03 12:00:00']];
        return $this->builder->buildBundle($iocs);
    }

    private function buildWithRelationship(): array
    {
        $rels = [[
            'source_indicator_id' => 'a', 'target_indicator_id' => 'b',
            'source_type' => 'domain', 'source_value_norm' => 'x.com',
            'target_type' => 'email', 'target_value_norm' => 'a@b.com',
            'weight' => 2,
        ]];
        return $this->builder->buildBundle([], $rels);
    }

    private function findObject(array $bundle, string $type): ?array
    {
        foreach ($bundle['objects'] as $obj) {
            if (($obj['type'] ?? '') === $type) {
                return $obj;
            }
        }
        return null;
    }

    private function findAllObjects(array $bundle, string $type): array
    {
        return array_values(array_filter($bundle['objects'], fn ($obj) => ($obj['type'] ?? '') === $type));
    }

    // ── TLP normalization: strtoupper ensures case-insensitivity ──

    public function testTlpLowercaseAmberNormalized(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'amber');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--f88d31f6-486f-44da-b317-01333bde0b82', $marking['id']);
    }

    public function testTlpMixedCaseGreenNormalized(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'Green');
        $marking = $this->findObject($bundle, 'marking-definition');
        self::assertSame('marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da', $marking['id']);
    }

    // ── Relationship limit ──

    public function testRelationshipLimitedTo100(): void
    {
        $rels = [];
        for ($i = 0; $i < 110; ++$i) {
            $rels[] = [
                'source_indicator_id' => "s{$i}", 'target_indicator_id' => "t{$i}",
                'source_type' => 'domain', 'source_value_norm' => "s{$i}.com",
                'target_type' => 'email', 'target_value_norm' => "t{$i}@b.com",
                'weight' => 1,
            ];
        }
        $bundle = $this->builder->buildBundle([], $rels);
        $relationships = $this->findAllObjects($bundle, 'relationship');
        self::assertLessThanOrEqual(100, count($relationships), 'Relationships must be capped at 100');
    }

    public function testRelationshipBreakNotContinueAt100(): void
    {
        // With exactly 101 relationships, only 100 should appear (break, not continue)
        $rels = [];
        for ($i = 0; $i < 101; ++$i) {
            $rels[] = [
                'source_indicator_id' => "s{$i}", 'target_indicator_id' => "t{$i}",
                'source_type' => 'domain', 'source_value_norm' => "s{$i}.com",
                'target_type' => 'email', 'target_value_norm' => "t{$i}@b.com",
                'weight' => 1,
            ];
        }
        $bundle = $this->builder->buildBundle([], $rels);
        $relationships = $this->findAllObjects($bundle, 'relationship');
        self::assertSame(100, count($relationships), 'Must stop at exactly 100 relationships');
    }

    // ── Report fields ──

    public function testReportTypeExact(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('report', $report['type']);
        self::assertSame('2.1', $report['spec_version']);
    }

    public function testReportNameDefault(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('ScamBuster IOC Export', $report['name']);
    }

    public function testReportCustomName(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER', 'My Report');
        $report = $this->findObject($bundle, 'report');
        self::assertSame('My Report', $report['name']);
    }

    public function testReportDescriptionDefault(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('Threat intelligence collected by ScamBuster automated honeypot', $report['description']);
    }

    public function testReportCustomDescription(): void
    {
        $bundle = $this->builder->buildBundle([], [], 'AMBER', 'Report', 'Custom desc');
        $report = $this->findObject($bundle, 'report');
        self::assertSame('Custom desc', $report['description']);
    }

    public function testReportLabelsContainThreatReportAndScam(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame(['threat-report', 'scam'], $report['labels']);
    }

    public function testReportObjectRefsIncludesIdentity(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertContains('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $report['object_refs']);
    }

    public function testReportObjectRefsIncludesIndicators(): void
    {
        $iocs = [['type' => 'domain', 'value' => 'evil.com', 'value_norm' => 'evil.com', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $report = $this->findObject($bundle, 'report');
        $ind = $this->findObject($bundle, 'indicator');
        self::assertContains($ind['id'], $report['object_refs']);
    }

    public function testReportCreatedByRefIsIdentity(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertSame('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $report['created_by_ref']);
    }

    public function testReportIdStartsWithReportPrefix(): void
    {
        $bundle = $this->builder->buildBundle([]);
        $report = $this->findObject($bundle, 'report');
        self::assertStringStartsWith('report--', $report['id']);
    }

    // ── Multiple IOCs ──

    public function testMultipleIocsProduceMultipleIndicators(): void
    {
        $iocs = [
            ['type' => 'domain', 'value' => 'a.com', 'value_norm' => 'a.com', 'first_seen' => '2026-01-01'],
            ['type' => 'email', 'value' => 'b@c.com', 'value_norm' => 'b@c.com', 'first_seen' => '2026-01-01'],
        ];
        $bundle = $this->builder->buildBundle($iocs);
        $indicators = $this->findAllObjects($bundle, 'indicator');
        self::assertCount(2, $indicators);
    }

    // ── Pattern for various types ──

    public function testPatternForUrlType(): void
    {
        $iocs = [['type' => 'url', 'value' => 'https://evil.com/phish', 'value_norm' => 'https://evil.com/phish', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[url:value = 'https://evil.com/phish']", $ind['pattern']);
    }

    public function testPatternForIpv4Type(): void
    {
        $iocs = [['type' => 'ipv4', 'value' => '192.168.1.1', 'value_norm' => '192.168.1.1', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[ipv4-addr:value = '192.168.1.1']", $ind['pattern']);
    }

    public function testPatternForSha256FileHash(): void
    {
        $iocs = [['type' => 'sha256', 'value' => 'abc123def', 'value_norm' => 'abc123def', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertSame("[file:hashes.'SHA-256' = 'abc123def']", $ind['pattern']);
    }

    public function testPatternForPhoneNumber(): void
    {
        $iocs = [['type' => 'phone', 'value' => '+33612345', 'value_norm' => '+33612345', 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertStringContainsString('+33612345', $ind['pattern']);
    }

    public function testPatternEscapesSingleQuoteChars(): void
    {
        $iocs = [['type' => 'domain', 'value' => "it's.evil.com", 'value_norm' => "it's.evil.com", 'first_seen' => '2026-01-01']];
        $bundle = $this->builder->buildBundle($iocs);
        $ind = $this->findObject($bundle, 'indicator');
        self::assertStringContainsString("\\'", $ind['pattern']);
    }
}
