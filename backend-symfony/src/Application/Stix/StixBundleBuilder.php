<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Communication\IocConfidenceCalculator;
use App\Application\Communication\IocDecayConfig;
use App\Application\Communication\IocExportMapper;

/**
 * Builds STIX 2.1 bundles compliant with OpenCTI import requirements.
 *
 * Generates: identity, marking-definitions, indicators, relationships, report.
 * All timestamps include milliseconds. No spec_version on bundle.
 */
final class StixBundleBuilder
{
    // Fixed ScamBuster identity UUID (deterministic across exports)
    private const IDENTITY_ID = 'identity--f431f809-377b-45e0-aa1c-6a4751cae5ff';

    // OpenCTI well-known TLP marking-definition UUIDs
    private const TLP_MARKING = [
        'WHITE' => 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9',
        'CLEAR' => 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9',
        'GREEN' => 'marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da',
        'AMBER' => 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82',
        'RED' => 'marking-definition--5e57c739-391a-4eb3-b6be-7d15ca92d5ed',
    ];

    // OpenCTI extension definition for custom properties
    private const OPENCTI_EXTENSION_ID = 'extension-definition--ea279b3e-5c71-4632-ac08-831c66a786ba';

    // IOC type → OpenCTI main observable type
    private const OPENCTI_OBSERVABLE_TYPE = [
        'email' => 'Email-Addr',
        'whois_email' => 'Email-Addr',
        'domain' => 'Domain-Name',
        'url' => 'Url',
        'ipv4' => 'IPv4-Addr',
        'ipv6' => 'IPv6-Addr',
        'ip' => 'IPv4-Addr',
        'md5' => 'StixFile',
        'sha1' => 'StixFile',
        'sha256' => 'StixFile',
        'phone' => 'Phone-Number',
        'iban' => 'Bank-Account',
        'bic' => 'Bank-Account',
        'wallet_btc' => 'Cryptocurrency-Wallet',
        'wallet_eth' => 'Cryptocurrency-Wallet',
        'wallet_xmr' => 'Cryptocurrency-Wallet',
        'telegram_username' => 'User-Account',
        'discord_username' => 'User-Account',
        'cve' => 'Vulnerability',
        'filename' => 'StixFile',
    ];

    // STIX pattern overrides for OpenCTI compatibility (financial IOCs)
    private const OPENCTI_PATTERN_OVERRIDE = [
        'phone' => 'x-opencti-phone-number',
        'iban' => 'x-opencti-bank-account',
        'bic' => 'x-opencti-bank-account',
        'wallet_btc' => 'x-opencti-cryptocurrency-wallet',
        'wallet_eth' => 'x-opencti-cryptocurrency-wallet',
        'wallet_xmr' => 'x-opencti-cryptocurrency-wallet',
        'bank_account' => 'x-opencti-bank-account',
        'credit_card' => 'x-opencti-bank-account',
    ];

    public function __construct(
        private readonly IocExportMapper $exportMapper
    ) {
    }

    /**
     * Build a STIX 2.1 bundle from IOC data.
     *
     * @param array<int, array<string, mixed>> $iocs          IOC data (type, value, value_norm, first_seen, confidence, score, extraction_method, etc.)
     * @param array<int, array<string, mixed>> $relationships Co-occurrence edges (source_indicator_id, target_indicator_id, weight)
     * @param string                           $tlp           TLP marking (WHITE, GREEN, AMBER, RED)
     * @param string                           $reportName    Report title
     * @param string|null                      $reportDesc    Report description
     *
     * @return array<string, mixed> STIX 2.1 bundle
     */
    public function buildBundle(
        array $iocs,
        array $relationships = [],
        string $tlp = 'AMBER',
        string $reportName = 'ScamBuster IOC Export',
        ?string $reportDesc = null,
    ): array {
        $bundleId = 'bundle--' . $this->uuid4();
        // Normalize TLP: strip "TLP:" or "TLP_" prefix
        $tlpNormalized = strtoupper((string) preg_replace('/^TLP[_:]/i', '', $tlp));
        $markingRef = self::TLP_MARKING[$tlpNormalized] ?? self::TLP_MARKING['AMBER'];
        $now = $this->formatTimestamp(new \DateTimeImmutable());

        /** @var array<int, array<string, mixed>> $objects */
        $objects = [];

        // 1. TLP marking-definition
        $objects[] = $this->buildMarkingDefinition($tlpNormalized, $markingRef);

        // 2. Identity
        $objects[] = $this->buildIdentity($markingRef);

        // 3. Indicators
        $indicatorIds = [];

        foreach ($iocs as $ioc) {
            $indicator = $this->buildIndicator($ioc, $markingRef, $now);

            if ($indicator !== null) {
                $objects[] = $indicator;
                $indicatorIds[] = $indicator['id'];
            }
        }

        // 4. Relationships (max 100)
        $relCount = 0;

        foreach ($relationships as $rel) {
            if ($relCount >= 100) {
                break;
            }

            $relationship = $this->buildRelationship($rel, $markingRef, $now);

            if ($relationship !== null) {
                $objects[] = $relationship;
                ++$relCount;
            }
        }

        // 5. Report
        $allObjectRefs = array_merge([self::IDENTITY_ID], $indicatorIds);
        $objects[] = [
            'type' => 'report',
            'spec_version' => '2.1',
            'id' => 'report--' . $this->uuid4(),
            'created' => $now,
            'modified' => $now,
            'created_by_ref' => self::IDENTITY_ID,
            'name' => $reportName,
            'description' => $reportDesc ?? 'Threat intelligence collected by ScamBuster automated honeypot',
            'published' => $now,
            'object_refs' => $allObjectRefs,
            'labels' => ['threat-report', 'scam'],
            'object_marking_refs' => [$markingRef],
        ];

        return [
            'type' => 'bundle',
            'id' => $bundleId,
            'objects' => $objects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMarkingDefinition(string $tlp, string $markingRef): array
    {
        // Normalize: strip "TLP:" or "TLP_" prefix, then lowercase
        $raw = preg_replace('/^TLP[_:]/i', '', $tlp) ?? $tlp;
        $tlpLower = strtolower($raw);

        if ($tlpLower === 'white') {
            $tlpLower = 'clear';
        }

        return [
            'type' => 'marking-definition',
            'spec_version' => '2.1',
            'id' => $markingRef,
            'created' => '2017-01-20T00:00:00.000Z',
            'definition_type' => 'tlp',
            'name' => 'TLP:' . strtoupper($tlpLower),
            'definition' => ['tlp' => $tlpLower],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIdentity(string $markingRef): array
    {
        return [
            'type' => 'identity',
            'spec_version' => '2.1',
            'id' => self::IDENTITY_ID,
            'created' => '2025-12-01T00:00:00.000Z',
            'modified' => '2025-12-01T00:00:00.000Z',
            'name' => 'ScamBuster Threat Intelligence',
            'description' => 'Automated scambaiting honeypot for threat intelligence collection',
            'identity_class' => 'system',
            'object_marking_refs' => [$markingRef],
        ];
    }

    /**
     * @param array<string, mixed> $ioc
     *
     * @return array<string, mixed>|null
     */
    private function buildIndicator(array $ioc, string $markingRef, string $now): ?array
    {
        $type = is_string($ioc['type'] ?? null) ? $ioc['type'] : '';
        $value = is_string($ioc['value'] ?? null) ? $ioc['value'] : '';
        $valueNorm = is_string($ioc['value_norm'] ?? null) ? $ioc['value_norm'] : $value;

        if ($type === '' || $value === '') {
            return null;
        }

        // Skip header IOCs — no value for CTI consumers
        $headerTypes = ['message_id', 'subject', 'spf_result', 'dkim_result', 'dmarc_result', 'x_mailer', 'return_path'];

        if (\in_array($type, $headerTypes, true)) {
            return null;
        }

        // Deterministic indicator ID from type:value_norm
        $indicatorId = 'indicator--' . $this->deterministicUuid('indicator:' . $type . ':' . strtolower($valueNorm));

        // Timestamps
        $firstSeen = $this->parseTimestamp($ioc['first_seen'] ?? null) ?? $now;
        $lastSeen = $this->parseTimestamp($ioc['last_seen'] ?? null) ?? $firstSeen;

        // valid_until from decay config
        $halfLifeDays = IocDecayConfig::getHalfLifeDays($type);

        try {
            $validUntilDt = (new \DateTimeImmutable($firstSeen))->modify("+{$halfLifeDays} days");
            $validUntil = $this->formatTimestamp($validUntilDt);
        } catch (\Exception) {
            $validUntil = null;
        }

        // Confidence from actual extraction data
        $confidence = 80;

        if (isset($ioc['confidence']) && is_numeric($ioc['confidence'])) {
            $confidence = (int) round((float) $ioc['confidence'] * 100);
        } elseif (isset($ioc['extraction_method']) && is_string($ioc['extraction_method'])) {
            $confidence = (int) (IocConfidenceCalculator::getBaseConfidence($ioc['extraction_method']) * 100);
        }

        $confidence = max(0, min(100, $confidence));

        // STIX pattern — use OpenCTI-compatible SCO types
        $pattern = $this->buildPattern($type, $valueNorm);

        // OpenCTI score: prefer VT/URLScan aggregate, fallback to confidence
        $score = 0;

        if (isset($ioc['score']) && is_array($ioc['score']) && isset($ioc['score']['agg']) && is_numeric($ioc['score']['agg']) && (int) $ioc['score']['agg'] > 0) {
            $score = (int) $ioc['score']['agg'];
        } else {
            // No enrichment data — use confidence as score (best available signal)
            $score = $confidence;
        }

        // Name
        $name = strtoupper($type) . ': ' . (mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '...' : $value);

        // Scam type label
        $labels = ['malicious-activity'];

        if (isset($ioc['scam_type']) && is_string($ioc['scam_type']) && $ioc['scam_type'] !== '') {
            $labels[] = strtolower($ioc['scam_type']);
        }

        $indicator = [
            'type' => 'indicator',
            'spec_version' => '2.1',
            'id' => $indicatorId,
            'created' => $firstSeen,
            'modified' => $lastSeen,
            'created_by_ref' => self::IDENTITY_ID,
            'name' => $name,
            'indicator_types' => ['malicious-activity'],
            'pattern' => $pattern,
            'pattern_type' => 'stix',
            'pattern_version' => '2.1',
            'valid_from' => $firstSeen,
            'confidence' => $confidence,
            'labels' => $labels,
            'object_marking_refs' => [$markingRef],
            'external_references' => [
                [
                    'source_name' => 'ScamBuster',
                    'external_id' => is_string($ioc['indicator_id'] ?? null) ? $ioc['indicator_id'] : $indicatorId,
                ],
            ],
        ];

        if ($validUntil !== null) {
            $indicator['valid_until'] = $validUntil;
        }

        // Extensions
        $extensions = [];

        // OpenCTI extension
        $observableType = self::OPENCTI_OBSERVABLE_TYPE[$type] ?? null;

        if ($observableType !== null) {
            $extensions[self::OPENCTI_EXTENSION_ID] = [
                'extension_type' => 'property-extension',
                'x_opencti_score' => $score,
                'x_opencti_main_observable_type' => $observableType,
            ];
        }

        // ScamBuster context extension (from ioc_context)
        $contextRow = \is_array($ioc['context'] ?? null) ? $ioc['context'] : null;

        if ($contextRow !== null) {
            $contextExt = IocContextStixExtensionBuilder::build($contextRow);

            if ($contextExt !== null) {
                $extensions['x_scambuster_context'] = $contextExt;
            }
        }

        if (!empty($extensions)) {
            $indicator['extensions'] = $extensions;
        }

        return $indicator;
    }

    /**
     * @param array<string, mixed> $rel
     *
     * @return array<string, mixed>|null
     */
    private function buildRelationship(array $rel, string $markingRef, string $now): ?array
    {
        $sourceId = is_string($rel['source_indicator_id'] ?? null) ? $rel['source_indicator_id'] : null;
        $targetId = is_string($rel['target_indicator_id'] ?? null) ? $rel['target_indicator_id'] : null;

        if ($sourceId === null || $targetId === null) {
            return null;
        }

        // Build deterministic STIX indicator IDs from the indicator_ids
        $sourceType = is_string($rel['source_type'] ?? null) ? $rel['source_type'] : 'unknown';
        $sourceValue = is_string($rel['source_value_norm'] ?? null) ? $rel['source_value_norm'] : '';
        $targetType = is_string($rel['target_type'] ?? null) ? $rel['target_type'] : 'unknown';
        $targetValue = is_string($rel['target_value_norm'] ?? null) ? $rel['target_value_norm'] : '';

        $sourceRef = 'indicator--' . $this->deterministicUuid('indicator:' . $sourceType . ':' . strtolower($sourceValue));
        $targetRef = 'indicator--' . $this->deterministicUuid('indicator:' . $targetType . ':' . strtolower($targetValue));

        $weight = is_numeric($rel['weight'] ?? null) ? (int) $rel['weight'] : 1;

        return [
            'type' => 'relationship',
            'spec_version' => '2.1',
            'id' => 'relationship--' . $this->deterministicUuid('rel:' . $sourceRef . ':' . $targetRef),
            'created' => $now,
            'modified' => $now,
            'created_by_ref' => self::IDENTITY_ID,
            'relationship_type' => 'related-to',
            'description' => "Co-observed in {$weight} conversation(s)",
            'source_ref' => $sourceRef,
            'target_ref' => $targetRef,
            'confidence' => min(50 + $weight * 10, 95),
            'object_marking_refs' => [$markingRef],
        ];
    }

    private function buildPattern(string $type, string $valueNorm): string
    {
        // Use OpenCTI-compatible SCO types for financial IOCs
        $scoType = self::OPENCTI_PATTERN_OVERRIDE[$type] ?? null;

        if ($scoType === null) {
            // Fallback to export mapper's mapping
            $context = $this->exportMapper->enrichWithExportMetadata([
                'type' => $type,
                'value' => $valueNorm,
                'value_norm' => $valueNorm,
            ]);
            $stixMeta = is_array($context['stix'] ?? null) ? $context['stix'] : [];
            $scoType = is_string($stixMeta['sco_type'] ?? null) ? $stixMeta['sco_type'] : 'artifact';
        }

        // Special pattern for file hashes
        if (\in_array($type, ['sha256', 'sha1', 'md5'], true)) {
            $hashType = strtoupper($type) === 'MD5' ? 'MD5' : strtoupper("SHA-{$type}");

            if ($type === 'sha256') {
                $hashType = 'SHA-256';
            } elseif ($type === 'sha1') {
                $hashType = 'SHA-1';
            }

            return "[file:hashes.'{$hashType}' = '{$this->escapePattern($valueNorm)}']";
        }

        return "[{$scoType}:value = '{$this->escapePattern($valueNorm)}']";
    }

    private function escapePattern(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function formatTimestamp(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private function parseTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->formatTimestamp(new \DateTimeImmutable($value));
        } catch (\Exception) {
            return null;
        }
    }

    private function uuid4(): string
    {
        return sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFFFFFFFFFF)
        );
    }

    private function deterministicUuid(string $input): string
    {
        $hash = md5($input);
        $hash[12] = '4';
        $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }
}
