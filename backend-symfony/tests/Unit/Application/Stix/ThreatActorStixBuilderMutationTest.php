<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ThreatActorStixBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Mutation-killing tests for ThreatActorStixBuilder.
 *
 * Targets: goals mapping, sophistication boundaries, description,
 * first_seen/last_seen, extension fields, MITRE techniques, formatScamTypeForName.
 */
final class ThreatActorStixBuilderMutationTest extends TestCase
{
    private ThreatActorStixBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ThreatActorStixBuilder();
    }

    // ── Goals mapping exact per scam type ──

    public function testGoalsAdvanceFee419(): void
    {
        $result = $this->build(['scam_type' => 'ADVANCE_FEE_419']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    public function testGoalsRomance(): void
    {
        $result = $this->build(['scam_type' => 'ROMANCE']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    public function testGoalsInvestment(): void
    {
        $result = $this->build(['scam_type' => 'INVESTMENT']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    public function testGoalsInvoiceFraud(): void
    {
        $result = $this->build(['scam_type' => 'INVOICE_FRAUD']);
        self::assertSame(['financial-theft', 'business-email-compromise'], $result['goals']);
    }

    public function testGoalsCeoFraud(): void
    {
        $result = $this->build(['scam_type' => 'CEO_FRAUD']);
        self::assertSame(['financial-theft', 'business-email-compromise'], $result['goals']);
    }

    public function testGoalsPhishing(): void
    {
        $result = $this->build(['scam_type' => 'PHISHING']);
        self::assertSame(['credential-theft'], $result['goals']);
    }

    public function testGoalsPhishCredentials(): void
    {
        $result = $this->build(['scam_type' => 'PHISH_CREDENTIALS']);
        self::assertSame(['credential-theft'], $result['goals']);
    }

    public function testGoalsPhishMalware(): void
    {
        $result = $this->build(['scam_type' => 'PHISH_MALWARE']);
        self::assertSame(['malware-deployment'], $result['goals']);
    }

    public function testGoalsTechSupport(): void
    {
        $result = $this->build(['scam_type' => 'TECH_SUPPORT']);
        self::assertSame(['financial-theft', 'remote-access'], $result['goals']);
    }

    public function testGoalsLottery(): void
    {
        $result = $this->build(['scam_type' => 'LOTTERY']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    public function testGoalsJobOffer(): void
    {
        $result = $this->build(['scam_type' => 'JOB_OFFER']);
        self::assertSame(['personal-data-theft'], $result['goals']);
    }

    public function testGoalsCharity(): void
    {
        $result = $this->build(['scam_type' => 'CHARITY']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    public function testGoalsUnknownFallsBackToFinancialTheft(): void
    {
        $result = $this->build(['scam_type' => 'NONEXISTENT']);
        self::assertSame(['financial-theft'], $result['goals']);
    }

    // ── Sophistication boundary tests ──

    public function testSophisticationScore0IsNone(): void
    {
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore1IsNone(): void
    {
        // avgHours=5 gives +1, rest 0 => score=1 => none
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 5, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore2IsMinimal(): void
    {
        // avgHours=25 gives +2 => score=2 => minimal
        self::assertSame('minimal', $this->builder->inferSophistication([
            'avg_engagement_hours' => 25, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore3IsMinimal(): void
    {
        // avgHours=25 (+2) + iocTypes=3 (+1) = 3 => minimal
        self::assertSame('minimal', $this->builder->inferSophistication([
            'avg_engagement_hours' => 25, 'avg_turns' => 0, 'unique_ioc_type_count' => 3, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore4IsIntermediate(): void
    {
        // avgHours=25 (+2) + iocTypes=5 (+2) = 4 => intermediate
        self::assertSame('intermediate', $this->builder->inferSophistication([
            'avg_engagement_hours' => 25, 'avg_turns' => 0, 'unique_ioc_type_count' => 5, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore5IsIntermediate(): void
    {
        // avgHours=25 (+2) + iocTypes=5 (+2) + avgTurns=8 (+1) = 5 => intermediate
        self::assertSame('intermediate', $this->builder->inferSophistication([
            'avg_engagement_hours' => 25, 'avg_turns' => 8, 'unique_ioc_type_count' => 5, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationScore6IsAdvanced(): void
    {
        // avgHours=25 (+2) + iocTypes=5 (+2) + avgTurns=16 (+2) = 6 => advanced
        self::assertSame('advanced', $this->builder->inferSophistication([
            'avg_engagement_hours' => 25, 'avg_turns' => 16, 'unique_ioc_type_count' => 5, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationInjectionAdds2(): void
    {
        // injection alone (+2) => minimal
        self::assertSame('minimal', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => true,
        ]));
    }

    public function testSophisticationBoundaryHours4NotCounted(): void
    {
        // exactly 4 hours: NOT > 4, so +0
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 4, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationBoundaryHours24NotAdvanced(): void
    {
        // exactly 24 hours: NOT > 24, so +1 not +2
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 24, 'avg_turns' => 0, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationBoundaryTurns7NotCounted(): void
    {
        // exactly 7 turns: NOT > 7, so +0
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 7, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationBoundaryTurns15NotAdvanced(): void
    {
        // exactly 15 turns: NOT > 15, so +1 not +2
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 15, 'unique_ioc_type_count' => 0, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationBoundaryIocTypes2NotCounted(): void
    {
        // exactly 2 types: NOT >= 3, so +0
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 0, 'unique_ioc_type_count' => 2, 'has_injection_attempts' => false,
        ]));
    }

    public function testSophisticationBoundaryIocTypes4NotAdvanced(): void
    {
        // exactly 4 types: >= 3 but NOT >= 5, so +1
        self::assertSame('none', $this->builder->inferSophistication([
            'avg_engagement_hours' => 0, 'avg_turns' => 0, 'unique_ioc_type_count' => 4, 'has_injection_attempts' => false,
        ]));
    }

    // ── Threat actor fields ──

    public function testThreatActorTypeExact(): void
    {
        $result = $this->build(['scam_type' => 'PHISHING']);
        self::assertSame('threat-actor', $result['type']);
    }

    public function testThreatActorSpecVersion21(): void
    {
        $result = $this->build(['scam_type' => 'PHISHING']);
        self::assertSame('2.1', $result['spec_version']);
    }

    public function testThreatActorIdPrefix(): void
    {
        $result = $this->build(['scam_type' => 'PHISHING']);
        self::assertStringStartsWith('threat-actor--', $result['id']);
    }

    public function testThreatActorTypesCriminal(): void
    {
        $result = $this->build([]);
        self::assertSame(['criminal'], $result['threat_actor_types']);
    }

    public function testThreatActorMotivationPersonalGain(): void
    {
        $result = $this->build([]);
        self::assertSame('personal-gain', $result['primary_motivation']);
    }

    public function testThreatActorCreatedByRef(): void
    {
        $result = $this->build([]);
        self::assertSame('identity--f431f809-377b-45e0-aa1c-6a4751cae5ff', $result['created_by_ref']);
    }

    public function testThreatActorNameFormatWithShortId(): void
    {
        $result = $this->build(['campaign_id' => '12345678-abcd-efgh-ijkl-mnopqrstuvwx', 'scam_type' => 'PHISHING']);
        self::assertSame('ScamBuster Actor - PHISHING #12345678', $result['name']);
    }

    public function testThreatActorLabelsContainScamAndType(): void
    {
        $result = $this->build(['scam_type' => 'ROMANCE']);
        self::assertSame(['scam', 'romance'], $result['labels']);
    }

    // ── First seen / last seen ──

    public function testFirstSeenPresentWhenProvided(): void
    {
        $result = $this->build(['first_seen' => '2026-01-15 10:00:00']);
        self::assertArrayHasKey('first_seen', $result);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $result['first_seen']);
    }

    public function testFirstSeenAbsentWhenEmpty(): void
    {
        $result = $this->build(['first_seen' => '']);
        self::assertArrayNotHasKey('first_seen', $result);
    }

    public function testLastSeenPresentWhenDifferentFromFirstSeen(): void
    {
        $result = $this->build(['first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-02-01 00:00:00']);
        self::assertArrayHasKey('last_seen', $result);
        self::assertSame('2026-02-01T00:00:00.000Z', $result['last_seen']);
    }

    public function testLastSeenAbsentWhenSameAsFirstSeen(): void
    {
        $result = $this->build(['first_seen' => '2026-01-01 00:00:00', 'last_seen' => '2026-01-01 00:00:00']);
        self::assertArrayNotHasKey('last_seen', $result);
    }

    public function testTimestampsHaveMilliseconds(): void
    {
        $result = $this->build(['first_seen' => '2026-01-01 00:00:00']);
        self::assertStringEndsWith('.000Z', $result['created']);
    }

    // ── Extension x_scambuster_actor ──

    public function testExtensionSchemaVersion10(): void
    {
        $result = $this->build([]);
        self::assertSame('1.0', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['schema_version']);
    }

    public function testExtensionCampaignId(): void
    {
        $result = $this->build(['campaign_id' => 'test-campaign-abc']);
        self::assertSame('test-campaign-abc', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['campaign_id']);
    }

    public function testExtensionScamType(): void
    {
        $result = $this->build(['scam_type' => 'LOTTERY']);
        self::assertSame('LOTTERY', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['scam_type']);
    }

    public function testExtensionConversationCount(): void
    {
        $result = $this->build([], null, ['conversation_count' => 42]);
        self::assertSame(42, $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['conversation_count']);
    }

    public function testExtensionConversationCountDefaultZero(): void
    {
        $result = $this->build([]);
        self::assertSame(0, $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['conversation_count']);
    }

    public function testExtensionStyleDnaPresent(): void
    {
        $profile = ['style_dna' => ['avg_len' => 12], 'infra_dna' => ['domains' => []]];
        $result = $this->build([], $profile);
        self::assertSame(['avg_len' => 12], $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['style_dna']);
    }

    public function testExtensionInfraDnaPresent(): void
    {
        $profile = ['style_dna' => [], 'infra_dna' => ['unique_domains' => ['evil.com']]];
        $result = $this->build([], $profile);
        self::assertSame(['unique_domains' => ['evil.com']], $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']['infra_dna']);
    }

    public function testExtensionNoDnaWithoutProfile(): void
    {
        $result = $this->build([], null);
        self::assertArrayNotHasKey('style_dna', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']);
        self::assertArrayNotHasKey('infra_dna', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']);
    }

    // ── Description ──

    public function testDescriptionFromYamlSummary(): void
    {
        $yaml = "campaign:\n  summary: \"Sophisticated phishing ring targeting banks\"\n";
        $result = $this->build(['profile_yaml' => $yaml]);
        self::assertSame('Sophisticated phishing ring targeting banks', $result['description']);
    }

    public function testDescriptionFallbackComputedContainsScamType(): void
    {
        $result = $this->build(['scam_type' => 'ROMANCE']);
        self::assertStringContainsString('Criminal actor operating romance campaigns', $result['description']);
    }

    public function testDescriptionContainsConversationCount(): void
    {
        $result = $this->build(['scam_type' => 'ROMANCE'], null, ['conversation_count' => 7]);
        self::assertStringContainsString('7 conversations observed', $result['description']);
    }

    public function testDescriptionContainsInfraDetails(): void
    {
        $profile = ['infra_dna' => ['unique_domains' => ['evil.com', 'bad.org'], 'payment_methods' => ['iban', 'btc'], 'tlds' => ['.com', '.org']]];
        $result = $this->build([], $profile, ['conversation_count' => 1]);
        self::assertStringContainsString('2 domains', $result['description']);
        self::assertStringContainsString('Payment methods: iban, btc', $result['description']);
    }

    public function testDescriptionYamlShortSummaryIgnored(): void
    {
        // Summary < 20 chars is not considered useful
        $yaml = "campaign:\n  summary: \"Short\"\n";
        $result = $this->build(['profile_yaml' => $yaml, 'scam_type' => 'PHISHING']);
        // Falls through to computed description
        self::assertStringContainsString('Criminal actor', $result['description']);
    }

    public function testDescriptionYamlLongLineExtracted(): void
    {
        $yaml = "campaign:\n  summary: \"Tiny\"\nThis is a long line that exceeds thirty characters and should be extracted as description\n";
        $result = $this->build(['profile_yaml' => $yaml]);
        self::assertStringContainsString('This is a long line', $result['description']);
    }

    // ── Marking ref resolution ──

    public function testMarkingRefAmberDefault(): void
    {
        $result = $this->build(['tlp' => 'AMBER']);
        self::assertSame(['marking-definition--f88d31f6-486f-44da-b317-01333bde0b82'], $result['object_marking_refs']);
    }

    public function testMarkingRefWhite(): void
    {
        $result = $this->build(['tlp' => 'WHITE']);
        self::assertSame(['marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9'], $result['object_marking_refs']);
    }

    public function testMarkingRefClear(): void
    {
        $result = $this->build(['tlp' => 'CLEAR']);
        self::assertSame(['marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9'], $result['object_marking_refs']);
    }

    public function testMarkingRefTlpPrefixStripped(): void
    {
        $result = $this->build(['tlp' => 'TLP:WHITE']);
        self::assertSame(['marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9'], $result['object_marking_refs']);
    }

    public function testMarkingRefUnknownDefaultsAmber(): void
    {
        $result = $this->build(['tlp' => 'PURPLE']);
        self::assertSame(['marking-definition--f88d31f6-486f-44da-b317-01333bde0b82'], $result['object_marking_refs']);
    }

    // ── MITRE attack patterns ──

    public function testAttackPatternT1566(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566');
        self::assertCount(1, $patterns);
        self::assertSame('Phishing', $patterns[0]['name']);
        self::assertSame('T1566', $patterns[0]['external_references'][0]['external_id']);
        self::assertSame('https://attack.mitre.org/techniques/T1566/', $patterns[0]['external_references'][0]['url']);
    }

    public function testAttackPatternT1566001(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566.001');
        self::assertSame('Phishing: Spearphishing Attachment', $patterns[0]['name']);
        self::assertSame('T1566.001', $patterns[0]['external_references'][0]['external_id']);
    }

    public function testAttackPatternT1566003(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566.003');
        self::assertSame('Phishing: Spearphishing via Service', $patterns[0]['name']);
    }

    public function testAttackPatternT1656Impersonation(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1656');
        self::assertCount(1, $patterns);
        self::assertSame('Impersonation', $patterns[0]['name']);
        self::assertSame('https://attack.mitre.org/techniques/T1656/', $patterns[0]['external_references'][0]['url']);
        self::assertSame('T1656', $patterns[0]['external_references'][0]['external_id']);
    }

    public function testAttackPatternSpecVersion21(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566');
        self::assertSame('2.1', $patterns[0]['spec_version']);
        self::assertSame('attack-pattern', $patterns[0]['type']);
    }

    public function testAttackPatternSourceNameMitreAttack(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566');
        self::assertSame('mitre-attack', $patterns[0]['external_references'][0]['source_name']);
    }

    public function testAttackPatternTlpWhiteMarking(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566');
        self::assertSame(['marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9'], $patterns[0]['object_marking_refs']);
    }

    public function testAttackPatternEmptyStringReturnsEmpty(): void
    {
        self::assertSame([], $this->builder->buildAttackPatterns(''));
    }

    // ── Actor relationships ──

    public function testActorRelationshipUsesType(): void
    {
        $rels = $this->builder->buildActorRelationships('ta-1', [], ['ap-1']);
        self::assertCount(1, $rels);
        self::assertSame('uses', $rels[0]['relationship_type']);
        self::assertSame('ta-1', $rels[0]['source_ref']);
        self::assertSame('ap-1', $rels[0]['target_ref']);
    }

    public function testActorRelationshipIndicatesType(): void
    {
        $rels = $this->builder->buildActorRelationships('ta-1', ['ind-1'], []);
        self::assertCount(1, $rels);
        self::assertSame('indicates', $rels[0]['relationship_type']);
        self::assertSame('ind-1', $rels[0]['source_ref']);
        self::assertSame('ta-1', $rels[0]['target_ref']);
    }

    public function testActorRelationshipCountCorrect(): void
    {
        $rels = $this->builder->buildActorRelationships('ta-1', ['ind-1', 'ind-2'], ['ap-1', 'ap-2']);
        // 2 uses + 2 indicates = 4
        self::assertCount(4, $rels);
    }

    public function testActorRelationshipsAllHaveSpecVersion(): void
    {
        $rels = $this->builder->buildActorRelationships('ta-1', ['ind-1'], ['ap-1']);
        foreach ($rels as $rel) {
            self::assertSame('2.1', $rel['spec_version']);
            self::assertSame('relationship', $rel['type']);
            self::assertStringStartsWith('relationship--', $rel['id']);
        }
    }

    public function testActorRelationshipsEmptyInputs(): void
    {
        $rels = $this->builder->buildActorRelationships('ta-1', [], []);
        self::assertCount(0, $rels);
    }

    // ── buildSingleton ──

    public function testSingletonNameFormatTitleCase(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => 'ADVANCE_FEE_419'], null, []);
        self::assertSame('Unattributed Scam Actor (Advance Fee 419)', $result['name']);
    }

    public function testSingletonNamePhishMalware(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => 'PHISH_MALWARE'], null, []);
        self::assertSame('Unattributed Scam Actor (Phish Malware)', $result['name']);
    }

    public function testSingletonNameTechSupport(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => 'TECH_SUPPORT'], null, []);
        self::assertSame('Unattributed Scam Actor (Tech Support)', $result['name']);
    }

    public function testSingletonNameJobOffer(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => 'JOB_OFFER'], null, []);
        self::assertSame('Unattributed Scam Actor (Job Offer)', $result['name']);
    }

    public function testSingletonNameCeoStaysUppercase(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => 'CEO_FRAUD'], null, []);
        self::assertSame('Unattributed Scam Actor (CEO Fraud)', $result['name']);
    }

    public function testSingletonNameEmptyScamType(): void
    {
        $result = $this->builder->buildSingleton(['campaign_id' => 'x', 'scam_type' => ''], null, []);
        self::assertSame('Unattributed Scam Actor (Unknown)', $result['name']);
    }

    // ── Helpers ──

    private function build(array $campaignOverrides = [], ?array $actorProfile = null, array $metrics = []): array
    {
        $campaign = array_merge(['campaign_id' => 'test-campaign-id', 'scam_type' => 'UNKNOWN'], $campaignOverrides);
        return $this->builder->buildThreatActor($campaign, $actorProfile, $metrics);
    }
}
