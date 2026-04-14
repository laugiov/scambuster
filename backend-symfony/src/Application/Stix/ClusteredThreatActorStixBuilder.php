<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Builds STIX 2.1 objects for a threat-actor cluster.
 *
 * Generates: threat-actor + attack-patterns + relationships (indicates, uses).
 * Uses cluster_type = "consolidated" in x_scambuster_actor extension.
 * NO attributed-to relationships (rejected by OpenCTI).
 *
 * Delegates attack-pattern and relationship building to ThreatActorStixBuilder.
 */
final readonly class ClusteredThreatActorStixBuilder
{
    private const IDENTITY_ID = 'identity--f431f809-377b-45e0-aa1c-6a4751cae5ff';
    private const TLP_AMBER = 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82';
    private const EXT_DEF_ACTOR_ID = 'extension-definition--c3b48d34-52e8-4f3a-ad9b-2b6a7e4f9c01';
    private const EXT_DEF_FINANCIAL_IOC_ID = 'extension-definition--d4c59e45-63f9-5a4b-be0c-3c7b8f5a0d12';

    /** @var array<string, list<string>> */
    private const GOALS_MAP = [
        'ADVANCE_FEE_419' => ['financial-theft'],
        'ROMANCE' => ['financial-theft'],
        'INVESTMENT' => ['financial-theft'],
        'INVOICE_FRAUD' => ['financial-theft', 'business-email-compromise'],
        'CEO_FRAUD' => ['financial-theft', 'business-email-compromise'],
        'PHISHING' => ['credential-theft'],
        'PHISH_CREDENTIALS' => ['credential-theft'],
        'PHISH_MALWARE' => ['malware-deployment'],
        'TECH_SUPPORT' => ['financial-theft', 'remote-access'],
        'LOTTERY' => ['financial-theft'],
        'JOB_OFFER' => ['personal-data-theft'],
        'CHARITY' => ['financial-theft'],
    ];

    private ThreatActorStixBuilder $actorBuilder;

    public function __construct()
    {
        $this->actorBuilder = new ThreatActorStixBuilder();
    }

    /**
     * Build STIX objects for a cluster.
     *
     * @param array<string, mixed> $clusterData Cluster data with keys:
     *                                          - cluster_id, stix_id, name, status, conversation_count, anchor_ioc_count
     *                                          - sophistication, primary_scam_types (array), goals (array)
     *                                          - first_seen, last_seen, algorithm_version
     *                                          - anchor_ioc_types (array), attck_techniques (array)
     *                                          - indicator_stix_ids (array, optional) — STIX IDs of anchor indicators
     *
     * @return list<array<string, mixed>> Flat array of STIX objects (threat-actor, attack-patterns, relationships)
     */
    public function buildBundle(array $clusterData): array
    {
        $objects = [];

        // Extension definitions (STIX 2.1 section 7.3)
        $objects[] = [
            'type' => 'extension-definition',
            'spec_version' => '2.1',
            'id' => self::EXT_DEF_ACTOR_ID,
            'created_by_ref' => self::IDENTITY_ID,
            'created' => '2025-12-01T00:00:00.000Z',
            'modified' => '2026-04-09T00:00:00.000Z',
            'name' => 'ScamBuster Threat Actor Extension',
            'description' => 'Cluster-level threat actor profiling: conversation count, anchor IOC types, clustering algorithm.',
            'schema' => 'https://github.com/laugiov/scambuster',
            'version' => '2.0',
            'extension_types' => ['property-extension'],
        ];
        $objects[] = [
            'type' => 'extension-definition',
            'spec_version' => '2.1',
            'id' => self::EXT_DEF_FINANCIAL_IOC_ID,
            'created_by_ref' => self::IDENTITY_ID,
            'created' => '2026-04-09T00:00:00.000Z',
            'modified' => '2026-04-09T00:00:00.000Z',
            'name' => 'ScamBuster Financial IOC Extension',
            'description' => 'Custom STIX patterns for financial IOCs without native SCO: phone, IBAN, crypto wallets, bank accounts.',
            'schema' => 'https://github.com/laugiov/scambuster',
            'version' => '1.0',
            'extension_types' => ['new-sco'],
        ];

        // Build threat-actor
        $actor = $this->buildThreatActor($clusterData);
        $objects[] = $actor;

        // Build attack-patterns from MITRE techniques
        /** @var list<string> $techniques */
        $techniques = \is_array($clusterData['attck_techniques'] ?? null) ? $clusterData['attck_techniques'] : [];
        $attackPatternIds = [];

        foreach ($techniques as $technique) {
            if ($technique === '') {
                continue;
            }

            $aps = $this->actorBuilder->buildAttackPatterns($technique);

            foreach ($aps as $ap) {
                $objects[] = $ap;
                $attackPatternIds[] = $ap['id'];
            }
        }

        // Build indicator objects from anchor IOC data
        /** @var list<array<string, mixed>> $indicatorData */
        $indicatorData = \is_array($clusterData['indicator_data'] ?? null) ? $clusterData['indicator_data'] : [];
        $indicatorStixIds = [];

        foreach ($indicatorData as $ind) {
            /** @var string $indId */
            $indId = $ind['indicator_id'] ?? '';
            /** @var string $indType */
            $indType = $ind['type'] ?? '';
            /** @var string $indValue */
            $indValue = $ind['value'] ?? '';

            if ($indId === '') {
                continue;
            }

            if ($indType === '') {
                continue;
            }

            $stixId = 'indicator--' . $indId;
            $indicatorStixIds[] = $stixId;

            $objects[] = [
                'type' => 'indicator',
                'spec_version' => '2.1',
                'id' => $stixId,
                'created_by_ref' => self::IDENTITY_ID,
                'indicator_types' => ['malicious-activity', 'attribution'],
                'name' => "{$indType}: {$indValue}",
                'pattern_type' => 'stix',
                'pattern' => $this->buildStixPattern($indType, $indValue),
                'valid_from' => $actor['created'],
                'labels' => ['anchor-ioc', $indType],
                'object_marking_refs' => [self::TLP_AMBER],
            ];
        }

        // Fallback: use pre-built indicator_stix_ids if no indicator_data
        if ($indicatorStixIds === []) {
            /** @var list<string> $indicatorStixIds */
            $indicatorStixIds = \is_array($clusterData['indicator_stix_ids'] ?? null) ? $clusterData['indicator_stix_ids'] : [];
        }

        // Build relationships
        /** @var string $actorId */
        $actorId = $actor['id'];

        $relationships = $this->actorBuilder->buildActorRelationships(
            $actorId,
            $indicatorStixIds,
            $attackPatternIds,
        );

        foreach ($relationships as $rel) {
            $objects[] = $rel;
        }

        return $objects;
    }

    /**
     * Spec 060 Sprint 2: build embeddable threat-actor objects for inclusion
     * inside a CONVERSATION export bundle (not a cluster export).
     *
     * Returns the cluster threat-actor + its attack-patterns + the relationships
     * (uses + indicates) ready to be appended to a conversation bundle. The
     * `indicates` relationships target the conversation's own indicator IDs
     * (passed as the second argument), NOT the cluster's anchor indicators.
     *
     * Indicator OBJECTS are NOT returned (those belong to the conversation
     * bundle, which already has them).
     *
     * @param array<string, mixed> $clusterData                  Same shape as buildBundle() expects
     * @param list<string>         $conversationIndicatorStixIds STIX IDs of the conversation's own indicators
     *
     * @return array{threat_actor: array<string, mixed>, attack_patterns: list<array<string, mixed>>, relationships: list<array<string, mixed>>}
     */
    public function buildThreatActorObjects(array $clusterData, array $conversationIndicatorStixIds): array
    {
        $threatActor = $this->buildThreatActor($clusterData);

        // Build attack-patterns from MITRE techniques
        /** @var list<string> $techniques */
        $techniques = \is_array($clusterData['attck_techniques'] ?? null) ? $clusterData['attck_techniques'] : [];
        $attackPatterns = [];
        $attackPatternIds = [];

        foreach ($techniques as $technique) {
            if ($technique === '') {
                continue;
            }

            $aps = $this->actorBuilder->buildAttackPatterns($technique);

            foreach ($aps as $ap) {
                $attackPatterns[] = $ap;
                $attackPatternIds[] = \is_string($ap['id']) ? $ap['id'] : '';
            }
        }

        // Build relationships:
        //   - threat-actor --uses--> attack-pattern (one per attack-pattern)
        //   - conversation_indicator --indicates--> threat-actor (one per conv indicator)
        /** @var string $actorId */
        $actorId = $threatActor['id'];

        $relationships = $this->actorBuilder->buildActorRelationships(
            $actorId,
            $conversationIndicatorStixIds,
            $attackPatternIds,
        );

        return [
            'threat_actor' => $threatActor,
            'attack_patterns' => $attackPatterns,
            'relationships' => $relationships,
        ];
    }

    /**
     * Build the STIX threat-actor object for a cluster.
     *
     * @param array<string, mixed> $clusterData
     *
     * @return array<string, mixed>
     */
    private function buildThreatActor(array $clusterData): array
    {
        /** @var string $stixId */
        $stixId = $clusterData['stix_id'] ?? '';
        /** @var string $name */
        $name = $clusterData['name'] ?? '';
        /** @var int $convCount */
        $convCount = \is_numeric($clusterData['conversation_count'] ?? null) ? (int) $clusterData['conversation_count'] : 0;
        /** @var string $sophistication */
        $sophistication = \is_string($clusterData['sophistication'] ?? null) ? $clusterData['sophistication'] : 'none';
        /** @var list<string> $scamTypes */
        $scamTypes = \is_array($clusterData['primary_scam_types'] ?? null) ? $clusterData['primary_scam_types'] : [];
        /** @var list<string> $anchorIocTypes */
        $anchorIocTypes = \is_array($clusterData['anchor_ioc_types'] ?? null) ? $clusterData['anchor_ioc_types'] : [];
        /** @var string $firstSeen */
        $firstSeen = \is_string($clusterData['first_seen'] ?? null) ? $clusterData['first_seen'] : '';
        /** @var string $lastSeen */
        $lastSeen = \is_string($clusterData['last_seen'] ?? null) ? $clusterData['last_seen'] : '';
        /** @var string $algorithmVersion */
        $algorithmVersion = \is_string($clusterData['algorithm_version'] ?? null) ? $clusterData['algorithm_version'] : '1.0';
        /** @var string $clusterId */
        $clusterId = \is_string($clusterData['cluster_id'] ?? null) ? $clusterData['cluster_id'] : '';

        // Resolve goals from weighted scam types (>=10% threshold) or all types as fallback
        /** @var list<array{code: string, count: int, pct: float}> $weightedTypes */
        $weightedTypes = \is_array($clusterData['weighted_scam_types'] ?? null) ? $clusterData['weighted_scam_types'] : [];
        $goals = empty($weightedTypes) ? $this->resolveGoals($scamTypes) : $this->resolveWeightedGoals($weightedTypes);

        // Build description
        $description = $this->buildDescription($convCount, $anchorIocTypes, $scamTypes, $firstSeen, $lastSeen);

        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');

        $actor = [
            'type' => 'threat-actor',
            'spec_version' => '2.1',
            'id' => $stixId,
            'created' => $this->parseTimestamp($firstSeen) ?? $now,
            'modified' => $this->parseTimestamp($lastSeen) ?? $now,
            'created_by_ref' => self::IDENTITY_ID,
            'name' => $name,
            'description' => $description,
            'threat_actor_types' => ['criminal'],
            'primary_motivation' => 'personal-gain',
            'sophistication' => $sophistication,
            'goals' => $goals,
            'labels' => ['scam', 'cluster'],
            'object_marking_refs' => [self::TLP_AMBER],
            'extensions' => [
                'x_scambuster_actor' => [
                    'schema_version' => '2.0',
                    'cluster_type' => 'consolidated',
                    'cluster_id' => $clusterId,
                    'conversation_count' => $convCount,
                    'anchor_ioc_types' => $anchorIocTypes,
                    'algorithm' => "realtime-anchor-ioc-clustering-v{$algorithmVersion}",
                ],
            ],
        ];

        if ($firstSeen !== '') {
            $actor['first_seen'] = $this->parseTimestamp($firstSeen) ?? $now;
        }

        if ($lastSeen !== '' && $lastSeen !== $firstSeen) {
            $actor['last_seen'] = $this->parseTimestamp($lastSeen) ?? $now;
        }

        return $actor;
    }

    /**
     * Resolve goals from scam type codes.
     *
     * @param list<string> $scamTypes
     *
     * @return list<string>
     */
    private function resolveGoals(array $scamTypes): array
    {
        $goals = [];

        foreach ($scamTypes as $type) {
            $typeGoals = self::GOALS_MAP[strtoupper($type)] ?? ['financial-theft'];

            foreach ($typeGoals as $g) {
                $goals[] = $g;
            }
        }

        return array_values(array_unique($goals)) ?: ['financial-theft'];
    }

    /**
     * Resolve goals from weighted scam types, including only types with >= 10% frequency.
     *
     * @param list<array{code: string, count: int, pct: float}> $weightedTypes
     *
     * @return list<string>
     */
    private function resolveWeightedGoals(array $weightedTypes): array
    {
        $significantTypes = array_filter($weightedTypes, fn (array $t): bool => $t['pct'] >= 10.0);

        if ($significantTypes === []) {
            $significantTypes = $weightedTypes;
        }

        $goals = [];

        foreach ($significantTypes as $t) {
            $typeGoals = self::GOALS_MAP[strtoupper($t['code'])] ?? ['financial-theft'];

            foreach ($typeGoals as $g) {
                $goals[] = $g;
            }
        }

        return array_values(array_unique($goals)) ?: ['financial-theft'];
    }

    /**
     * Auto-generate a description from cluster data.
     *
     * @param list<string> $anchorIocTypes
     * @param list<string> $scamTypes
     */
    private function buildDescription(int $convCount, array $anchorIocTypes, array $scamTypes, string $firstSeen, string $lastSeen): string
    {
        $iocStr = implode(', ', $anchorIocTypes) ?: 'financial IOCs';
        $scamStr = implode(', ', array_map(fn (string $s): string => ucfirst(strtolower(str_replace('_', ' ', $s))), $scamTypes)) ?: 'Unknown';
        $dateRange = '';

        if ($firstSeen !== '' && $lastSeen !== '') {
            try {
                $from = (new \DateTimeImmutable($firstSeen))->format('Y-m-d');
                $to = (new \DateTimeImmutable($lastSeen))->format('Y-m-d');
                $dateRange = " Active {$from} to {$to}.";
            } catch (\Exception) {
                // ignore
            }
        }

        return "Activity cluster of {$convCount} conversations sharing financial IOCs ({$iocStr}). Scam types: {$scamStr}.{$dateRange}";
    }

    private function buildStixPattern(string $type, string $value): string
    {
        $escaped = str_replace("'", "\\'", $value);

        return match ($type) {
            'iban' => "[x-scambuster:iban = '{$escaped}']",
            'phone' => "[x-scambuster:phone = '{$escaped}']",
            'wallet_btc' => "[x-scambuster:wallet_btc = '{$escaped}']",
            'wallet_eth' => "[x-scambuster:wallet_eth = '{$escaped}']",
            'wallet_xmr' => "[x-scambuster:wallet_xmr = '{$escaped}']",
            'credit_card' => "[x-scambuster:credit_card = '{$escaped}']",
            'bank_account' => "[x-scambuster:bank_account = '{$escaped}']",
            default => "[x-scambuster:value = '{$escaped}']",
        };
    }

    private function parseTimestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
