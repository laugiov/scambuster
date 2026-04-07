<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Builds STIX 2.1 threat-actor, attack-pattern, and relationship objects
 * from Campaign + ActorProfile data.
 *
 * Each campaign produces one threat-actor with:
 * - Deterministic UUID (idempotent exports for OpenCTI/MISP dedup)
 * - Sophistication inferred from campaign metrics
 * - Goals mapped from scam type
 * - x_scambuster_actor extension with style_dna + infra_dna
 * - Linked attack-patterns from MITRE ATT&CK mapping
 */
final class ThreatActorStixBuilder
{
    // ScamBuster identity (same as StixBundleBuilder)
    private const IDENTITY_ID = 'identity--f431f809-377b-45e0-aa1c-6a4751cae5ff';

    // TLP marking definitions (OpenCTI standard UUIDs)
    private const TLP_AMBER = 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82';
    private const TLP_WHITE = 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9';

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

    /** @var array<string, array{name: string, url: string}> */
    private const MITRE_TECHNIQUES = [
        'T1566' => [
            'name' => 'Phishing',
            'url' => 'https://attack.mitre.org/techniques/T1566/',
        ],
        'T1566.001' => [
            'name' => 'Phishing: Spearphishing Attachment',
            'url' => 'https://attack.mitre.org/techniques/T1566/001/',
        ],
        'T1566.002' => [
            'name' => 'Phishing: Spearphishing Link',
            'url' => 'https://attack.mitre.org/techniques/T1566/002/',
        ],
        'T1566.003' => [
            'name' => 'Phishing: Spearphishing via Service',
            'url' => 'https://attack.mitre.org/techniques/T1566/003/',
        ],
        'T1566.004' => [
            'name' => 'Phishing: Spearphishing Voice',
            'url' => 'https://attack.mitre.org/techniques/T1566/004/',
        ],
        'T1534' => [
            'name' => 'Internal Spearphishing',
            'url' => 'https://attack.mitre.org/techniques/T1534/',
        ],
    ];

    /**
     * Build a STIX threat-actor object from campaign data.
     *
     * @param array<string, mixed>      $campaignData Campaign row (campaign_id, scam_type, first_seen, profile_yaml, etc.)
     * @param array<string, mixed>|null $actorProfile ActorProfile row (style_dna, infra_dna) or null if no profile
     * @param array<string, mixed>      $metrics      Campaign metrics (avg_engagement_hours, avg_turns, unique_ioc_type_count, has_injection_attempts, conversation_count)
     *
     * @return array<string, mixed> STIX threat-actor object
     */
    public function buildThreatActor(array $campaignData, ?array $actorProfile, array $metrics): array
    {
        $campaignId = \is_string($campaignData['campaign_id'] ?? null) ? $campaignData['campaign_id'] : '';
        $scamType = \is_string($campaignData['scam_type'] ?? null) ? strtoupper($campaignData['scam_type']) : 'UNKNOWN';
        $firstSeen = \is_string($campaignData['first_seen'] ?? null) ? $campaignData['first_seen'] : '';
        $lastSeen = \is_string($campaignData['last_seen'] ?? null) ? $campaignData['last_seen'] : $firstSeen;
        $profileYaml = \is_string($campaignData['profile_yaml'] ?? null) ? $campaignData['profile_yaml'] : null;
        $tlp = \is_string($campaignData['tlp'] ?? null) ? $campaignData['tlp'] : 'AMBER';

        $shortId = substr($campaignId, 0, 8);
        $sophistication = $this->inferSophistication($metrics);
        $goals = self::GOALS_MAP[$scamType] ?? ['financial-theft'];
        $description = $this->buildDescription($scamType, $profileYaml, $actorProfile, $metrics);

        $markingRef = $this->resolveMarkingRef($tlp);
        $now = $this->formatTimestamp(new \DateTimeImmutable());

        $threatActor = [
            'type' => 'threat-actor',
            'spec_version' => '2.1',
            'id' => 'threat-actor--' . $this->deterministicUuid('scambuster-threat-actor-' . $campaignId),
            'created' => $this->parseTimestamp($firstSeen) ?? $now,
            'modified' => $this->parseTimestamp($lastSeen) ?? $now,
            'created_by_ref' => self::IDENTITY_ID,
            'name' => sprintf('ScamBuster Actor - %s #%s', $scamType, $shortId),
            'description' => $description,
            'threat_actor_types' => ['criminal'],
            'primary_motivation' => 'personal-gain',
            'sophistication' => $sophistication,
            'goals' => $goals,
            'labels' => ['scam', strtolower($scamType)],
            'object_marking_refs' => [$markingRef],
        ];

        if ($firstSeen !== '') {
            $threatActor['first_seen'] = $this->parseTimestamp($firstSeen) ?? $now;
        }

        if ($lastSeen !== '' && $lastSeen !== $firstSeen) {
            $threatActor['last_seen'] = $this->parseTimestamp($lastSeen) ?? $now;
        }

        // Add x_scambuster_actor extension
        $extension = [
            'schema_version' => '1.0',
            'campaign_id' => $campaignId,
            'scam_type' => $scamType,
            'conversation_count' => \is_numeric($metrics['conversation_count'] ?? null) ? (int) $metrics['conversation_count'] : 0,
        ];

        if ($actorProfile !== null) {
            if (\is_array($actorProfile['style_dna'] ?? null)) {
                $extension['style_dna'] = $actorProfile['style_dna'];
            }

            if (\is_array($actorProfile['infra_dna'] ?? null)) {
                $extension['infra_dna'] = $actorProfile['infra_dna'];
            }
        }

        $threatActor['extensions'] = ['x_scambuster_actor' => $extension];

        return $threatActor;
    }

    /**
     * Build STIX attack-pattern objects for a MITRE technique.
     *
     * @return list<array<string, mixed>> Array of attack-pattern objects (usually 1)
     */
    public function buildAttackPatterns(?string $attckTechnique): array
    {
        if ($attckTechnique === null || $attckTechnique === '') {
            return [];
        }

        $technique = self::MITRE_TECHNIQUES[$attckTechnique] ?? null;

        if ($technique === null) {
            return [];
        }

        return [[
            'type' => 'attack-pattern',
            'spec_version' => '2.1',
            'id' => 'attack-pattern--' . $this->deterministicUuid('mitre-attack-' . $attckTechnique),
            'created_by_ref' => self::IDENTITY_ID,
            'name' => $technique['name'],
            'external_references' => [[
                'source_name' => 'mitre-attack',
                'url' => $technique['url'],
                'external_id' => $attckTechnique,
            ]],
            'object_marking_refs' => [self::TLP_WHITE],
        ]];
    }

    /**
     * Build STIX relationships linking threat-actor to indicators and attack-patterns.
     *
     * @param string       $threatActorId    STIX ID of threat-actor
     * @param list<string> $indicatorIds     STIX IDs of indicators
     * @param list<string> $attackPatternIds STIX IDs of attack-patterns
     *
     * @return list<array<string, mixed>>
     */
    public function buildActorRelationships(
        string $threatActorId,
        array $indicatorIds,
        array $attackPatternIds,
        string $markingRef = self::TLP_AMBER,
    ): array {
        $relationships = [];
        $now = $this->formatTimestamp(new \DateTimeImmutable());

        // threat-actor --uses--> attack-pattern
        foreach ($attackPatternIds as $apId) {
            $relationships[] = [
                'type' => 'relationship',
                'spec_version' => '2.1',
                'id' => 'relationship--' . $this->deterministicUuid('uses-' . $threatActorId . '-' . $apId),
                'created' => $now,
                'modified' => $now,
                'relationship_type' => 'uses',
                'source_ref' => $threatActorId,
                'target_ref' => $apId,
                'created_by_ref' => self::IDENTITY_ID,
                'object_marking_refs' => [$markingRef],
            ];
        }

        // indicator --indicates--> threat-actor
        foreach ($indicatorIds as $indId) {
            $relationships[] = [
                'type' => 'relationship',
                'spec_version' => '2.1',
                'id' => 'relationship--' . $this->deterministicUuid('indicates-' . $indId . '-' . $threatActorId),
                'created' => $now,
                'modified' => $now,
                'relationship_type' => 'indicates',
                'source_ref' => $indId,
                'target_ref' => $threatActorId,
                'created_by_ref' => self::IDENTITY_ID,
                'object_marking_refs' => [$markingRef],
            ];
        }

        return $relationships;
    }

    /**
     * Infer sophistication level from campaign metrics.
     *
     * @param array<string, mixed> $metrics
     */
    public function inferSophistication(array $metrics): string
    {
        $score = 0;

        $avgHours = \is_numeric($metrics['avg_engagement_hours'] ?? null) ? (float) $metrics['avg_engagement_hours'] : 0.0;

        if ($avgHours > 24) {
            $score += 2;
        } elseif ($avgHours > 4) {
            $score += 1;
        }

        $iocTypeCount = \is_numeric($metrics['unique_ioc_type_count'] ?? null) ? (int) $metrics['unique_ioc_type_count'] : 0;

        if ($iocTypeCount >= 5) {
            $score += 2;
        } elseif ($iocTypeCount >= 3) {
            $score += 1;
        }

        $avgTurns = \is_numeric($metrics['avg_turns'] ?? null) ? (float) $metrics['avg_turns'] : 0.0;

        if ($avgTurns > 15) {
            $score += 2;
        } elseif ($avgTurns > 7) {
            $score += 1;
        }

        $hasInjection = !empty($metrics['has_injection_attempts']);

        if ($hasInjection) {
            $score += 2;
        }

        return match (true) {
            $score >= 6 => 'advanced',
            $score >= 4 => 'intermediate',
            $score >= 2 => 'minimal',
            default => 'none',
        };
    }

    /**
     * @param array<string, mixed>|null $actorProfile
     * @param array<string, mixed>      $metrics
     */
    private function buildDescription(string $scamType, ?string $profileYaml, ?array $actorProfile, array $metrics): string
    {
        // Try to extract summary from profile_yaml
        if ($profileYaml !== null && $profileYaml !== '') {
            // profile_yaml is YAML with a campaign.summary section
            if (preg_match('/summary:\s*["\']?(.+?)["\']?\s*(?:\n|$)/i', $profileYaml, $matches)) {
                $summary = trim($matches[1]);

                if (\strlen($summary) > 20) {
                    return $summary;
                }
            }

            // Try extracting first meaningful line
            $lines = explode("\n", $profileYaml);

            foreach ($lines as $line) {
                $trimmed = trim($line, " \t\n\r-#");

                if (\strlen($trimmed) > 30 && !str_starts_with($trimmed, 'campaign') && !str_starts_with($trimmed, 'variants')) {
                    return mb_substr($trimmed, 0, 400);
                }
            }
        }

        // Fallback: computed description from available data
        $parts = [sprintf('Criminal actor operating %s campaigns.', strtolower($scamType))];

        if ($actorProfile !== null) {
            $infra = $actorProfile['infra_dna'] ?? [];

            if (\is_array($infra)) {
                $domains = $infra['unique_domains'] ?? [];
                $payment = $infra['payment_methods'] ?? [];
                $tlds = $infra['tlds'] ?? [];

                if (\is_array($domains) && \count($domains) > 0) {
                    $parts[] = sprintf('Infrastructure: %d domains (%s).', \count($domains), implode(', ', \array_slice($tlds, 0, 3)));
                }

                if (\is_array($payment) && \count($payment) > 0) {
                    $parts[] = sprintf('Payment methods: %s.', implode(', ', $payment));
                }
            }
        }

        $convCount = \is_numeric($metrics['conversation_count'] ?? null) ? (int) $metrics['conversation_count'] : 0;

        if ($convCount > 0) {
            $parts[] = sprintf('%d conversations observed.', $convCount);
        }

        return implode(' ', $parts);
    }

    private function resolveMarkingRef(string $tlp): string
    {
        $normalized = strtoupper((string) preg_replace('/^TLP[_:]/i', '', $tlp));

        return match ($normalized) {
            'WHITE', 'CLEAR' => self::TLP_WHITE,
            default => self::TLP_AMBER,
        };
    }

    private function deterministicUuid(string $input): string
    {
        $hash = md5($input);

        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((int) (hexdec(substr($hash, 16, 2)) & 0x3F | 0x80)) . substr($hash, 18, 2),
            substr($hash, 20, 12)
        );
    }

    private function formatTimestamp(\DateTimeImmutable $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s.v\Z');
    }

    private function parseTimestamp(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return $this->formatTimestamp(new \DateTimeImmutable($value));
        } catch (\Exception) {
            return null;
        }
    }
}
