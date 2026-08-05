<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ThreatActorStixBuilder;
use PHPUnit\Framework\TestCase;

final class ThreatActorStixBuilderTest extends TestCase
{
    private ThreatActorStixBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ThreatActorStixBuilder();
    }

    public function testDeterministicUuid(): void
    {
        $campaign = ['campaign_id' => 'test-campaign-123', 'scam_type' => 'PHISHING'];
        $metrics = ['conversation_count' => 5];

        $result1 = $this->builder->buildThreatActor($campaign, null, $metrics);
        $result2 = $this->builder->buildThreatActor($campaign, null, $metrics);

        self::assertSame($result1['id'], $result2['id']);
        self::assertStringStartsWith('threat-actor--', $result1['id']);
    }

    public function testDifferentCampaignIdProducesDifferentUuid(): void
    {
        $metrics = ['conversation_count' => 5];

        $result1 = $this->builder->buildThreatActor(['campaign_id' => 'campaign-A', 'scam_type' => 'PHISHING'], null, $metrics);
        $result2 = $this->builder->buildThreatActor(['campaign_id' => 'campaign-B', 'scam_type' => 'PHISHING'], null, $metrics);

        self::assertNotSame($result1['id'], $result2['id']);
    }

    public function testAlwaysCriminalAndPersonalGain(): void
    {
        $campaign = ['campaign_id' => 'test', 'scam_type' => 'ROMANCE'];
        $result = $this->builder->buildThreatActor($campaign, null, []);

        self::assertSame(['criminal'], $result['threat_actor_types']);
        self::assertSame('personal-gain', $result['primary_motivation']);
    }

    public function testGoalsMapping(): void
    {
        $metrics = [];

        $phishing = $this->builder->buildThreatActor(['campaign_id' => 'c1', 'scam_type' => 'PHISHING'], null, $metrics);
        self::assertSame(['credential-theft'], $phishing['goals']);

        $invoice = $this->builder->buildThreatActor(['campaign_id' => 'c2', 'scam_type' => 'INVOICE_FRAUD'], null, $metrics);
        self::assertSame(['financial-theft', 'business-email-compromise'], $invoice['goals']);

        $malware = $this->builder->buildThreatActor(['campaign_id' => 'c3', 'scam_type' => 'PHISH_MALWARE'], null, $metrics);
        self::assertSame(['malware-deployment'], $malware['goals']);
    }

    public function testSophisticationNone(): void
    {
        $result = $this->builder->inferSophistication([
            'avg_engagement_hours' => 0,
            'avg_turns' => 0,
            'unique_ioc_type_count' => 0,
            'has_injection_attempts' => false,
        ]);

        self::assertSame('none', $result);
    }

    public function testSophisticationMinimal(): void
    {
        $result = $this->builder->inferSophistication([
            'avg_engagement_hours' => 5,
            'avg_turns' => 3,
            'unique_ioc_type_count' => 3,
            'has_injection_attempts' => false,
        ]);

        self::assertSame('minimal', $result);
    }

    public function testSophisticationIntermediate(): void
    {
        $result = $this->builder->inferSophistication([
            'avg_engagement_hours' => 10,
            'avg_turns' => 8,
            'unique_ioc_type_count' => 5,
            'has_injection_attempts' => false,
        ]);

        self::assertSame('intermediate', $result);
    }

    public function testSophisticationAdvanced(): void
    {
        $result = $this->builder->inferSophistication([
            'avg_engagement_hours' => 48,
            'avg_turns' => 20,
            'unique_ioc_type_count' => 7,
            'has_injection_attempts' => true,
        ]);

        self::assertSame('advanced', $result);
    }

    public function testCampaignWithoutActorProfileProducesValidObject(): void
    {
        $campaign = ['campaign_id' => 'no-profile', 'scam_type' => 'LOTTERY', 'first_seen' => '2026-01-01 00:00:00'];
        $result = $this->builder->buildThreatActor($campaign, null, ['conversation_count' => 3]);

        self::assertSame('threat-actor', $result['type']);
        self::assertSame('2.1', $result['spec_version']);
        self::assertStringContains('LOTTERY', $result['name']);
        self::assertArrayHasKey('extensions', $result);
        self::assertArrayHasKey(\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID, $result['extensions']);
        self::assertArrayNotHasKey('style_dna', $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor']);
    }

    public function testCampaignWithActorProfileIncludesExtension(): void
    {
        $campaign = ['campaign_id' => 'with-profile', 'scam_type' => 'PHISHING'];
        $actorProfile = [
            'style_dna' => ['avg_sentence_length' => 18.5, 'vocabulary_size' => 340],
            'infra_dna' => ['unique_domains' => ['evil.com'], 'payment_methods' => ['iban']],
        ];

        $result = $this->builder->buildThreatActor($campaign, $actorProfile, ['conversation_count' => 8]);

        $ext = $result['extensions'][\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID]['x_scambuster_actor'];
        self::assertSame('1.0', $ext['schema_version']);
        self::assertSame(['avg_sentence_length' => 18.5, 'vocabulary_size' => 340], $ext['style_dna']);
        self::assertSame(['unique_domains' => ['evil.com'], 'payment_methods' => ['iban']], $ext['infra_dna']);
    }

    public function testDescriptionFromProfileYaml(): void
    {
        $yaml = "campaign:\n  summary: \"Sophisticated phishing ring targeting French banks\"\n  tactics: urgency";
        $campaign = ['campaign_id' => 'yaml-test', 'scam_type' => 'PHISHING', 'profile_yaml' => $yaml];
        $result = $this->builder->buildThreatActor($campaign, null, []);

        self::assertSame('Sophisticated phishing ring targeting French banks', $result['description']);
    }

    public function testDescriptionFallbackWhenNoYaml(): void
    {
        $campaign = ['campaign_id' => 'no-yaml', 'scam_type' => 'LOTTERY'];
        $result = $this->builder->buildThreatActor($campaign, null, ['conversation_count' => 5]);

        self::assertStringContains('Criminal actor operating lottery campaigns', $result['description']);
        self::assertStringContains('5 conversations', $result['description']);
    }

    public function testAttackPatternWithValidTechnique(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T1566.002');

        self::assertCount(1, $patterns);
        self::assertSame('attack-pattern', $patterns[0]['type']);
        self::assertSame('Phishing: Spearphishing Link', $patterns[0]['name']);
        self::assertStringStartsWith('attack-pattern--', $patterns[0]['id']);

        // TLP:WHITE for public MITRE data
        $whiteMarking = 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9';
        self::assertContains($whiteMarking, $patterns[0]['object_marking_refs']);

        // External reference
        $extRef = $patterns[0]['external_references'][0];
        self::assertSame('mitre-attack', $extRef['source_name']);
        self::assertSame('T1566.002', $extRef['external_id']);
    }

    public function testAttackPatternDeterministicUuid(): void
    {
        $patterns1 = $this->builder->buildAttackPatterns('T1566.002');
        $patterns2 = $this->builder->buildAttackPatterns('T1566.002');

        self::assertSame($patterns1[0]['id'], $patterns2[0]['id']);
    }

    public function testAttackPatternNullTechnique(): void
    {
        $patterns = $this->builder->buildAttackPatterns(null);

        self::assertSame([], $patterns);
    }

    public function testAttackPatternUnknownTechnique(): void
    {
        $patterns = $this->builder->buildAttackPatterns('T9999');

        self::assertSame([], $patterns);
    }

    public function testBuildActorRelationships(): void
    {
        $threatActorId = 'threat-actor--aaa';
        $indicatorIds = ['indicator--c1', 'indicator--c2'];
        $attackPatternIds = ['attack-pattern--d1'];

        $rels = $this->builder->buildActorRelationships($threatActorId, $indicatorIds, $attackPatternIds);

        // 1 uses + 2 indicates = 3
        self::assertCount(3, $rels);

        // Check uses
        $uses = $rels[0];
        self::assertSame('relationship', $uses['type']);
        self::assertSame('uses', $uses['relationship_type']);
        self::assertSame($threatActorId, $uses['source_ref']);
        self::assertSame('attack-pattern--d1', $uses['target_ref']);

        // Check indicates
        $indicates1 = $rels[1];
        self::assertSame('indicates', $indicates1['relationship_type']);
        self::assertSame('indicator--c1', $indicates1['source_ref']);
        self::assertSame($threatActorId, $indicates1['target_ref']);
    }

    // ============================================================================
    // buildSingleton() for un-clustered conversation exports
    // ============================================================================
    //
    // When a conversation does not belong to any cluster, the conversation
    // export emits a per-conversation threat-actor with a human-readable name
    // that does NOT embed the campaign UUID:
    //   "Unattributed Scam Actor (Invoice Fraud)" instead of
    //   "ScamBuster Actor - INVOICE_FRAUD #12d9071c"
    // The STIX `id` still embeds the deterministic UUID for OpenCTI dedup.

    public function testBuildSingletonNamingConventionInvoiceFraud(): void
    {
        $campaign = ['campaign_id' => '12d9071c-849d-4f31-a9c1-ea53e1a0a84c', 'scam_type' => 'INVOICE_FRAUD'];
        $actor = $this->builder->buildSingleton($campaign, null, ['conversation_count' => 1]);

        self::assertSame('Unattributed Scam Actor (Invoice Fraud)', $actor['name']);
    }

    public function testBuildSingletonNamingConventionCeoFraud(): void
    {
        $campaign = ['campaign_id' => 'abc123', 'scam_type' => 'CEO_FRAUD'];
        $actor = $this->builder->buildSingleton($campaign, null, ['conversation_count' => 1]);

        self::assertSame('Unattributed Scam Actor (CEO Fraud)', $actor['name']);
    }

    public function testBuildSingletonNamingConventionRomance(): void
    {
        $campaign = ['campaign_id' => 'xyz', 'scam_type' => 'ROMANCE'];
        $actor = $this->builder->buildSingleton($campaign, null, []);

        self::assertSame('Unattributed Scam Actor (Romance)', $actor['name']);
    }

    public function testBuildSingletonNamingConventionUnknown(): void
    {
        $campaign = ['campaign_id' => 'no-type'];
        $actor = $this->builder->buildSingleton($campaign, null, []);

        self::assertSame('Unattributed Scam Actor (Unknown)', $actor['name']);
    }

    public function testBuildSingletonStillEmitsValidStixObject(): void
    {
        $campaign = [
            'campaign_id' => 'abc-123',
            'scam_type' => 'PHISHING',
            'first_seen' => '2026-04-01 12:00:00',
            'last_seen' => '2026-04-02 14:00:00',
        ];
        $actor = $this->builder->buildSingleton($campaign, null, ['conversation_count' => 1]);

        self::assertSame('threat-actor', $actor['type']);
        self::assertSame('2.1', $actor['spec_version']);
        self::assertStringStartsWith('threat-actor--', $actor['id']);
        self::assertSame(['criminal'], $actor['threat_actor_types']);
        self::assertSame('personal-gain', $actor['primary_motivation']);
        self::assertArrayHasKey('sophistication', $actor);
        self::assertArrayHasKey('goals', $actor);
        self::assertArrayHasKey('extensions', $actor);
        self::assertArrayHasKey(\App\Application\Stix\ScambusterStixExtensions::ACTOR_ID, $actor['extensions']);
    }

    public function testBuildSingletonProducesDeterministicId(): void
    {
        $campaign = ['campaign_id' => 'same-id', 'scam_type' => 'PHISHING'];

        $a = $this->builder->buildSingleton($campaign, null, []);
        $b = $this->builder->buildSingleton($campaign, null, []);

        self::assertSame($a['id'], $b['id']);
    }

    public function testBuildSingletonAndBuildThreatActorShareSameIdForSameCampaign(): void
    {
        // The singleton uses the same deterministic UUID strategy as buildThreatActor
        // so OpenCTI sees them as the same actor across migrations.
        $campaign = ['campaign_id' => 'shared-id', 'scam_type' => 'PHISHING'];

        $singleton = $this->builder->buildSingleton($campaign, null, []);
        $regular = $this->builder->buildThreatActor($campaign, null, []);

        self::assertSame($singleton['id'], $regular['id']);
    }

    /**
     * Helper to check string contains substring (PHP 8.3 compatible).
     */
    private static function assertStringContains(string $needle, string $haystack): void
    {
        self::assertTrue(str_contains($haystack, $needle), sprintf('Failed asserting that "%s" contains "%s"', $haystack, $needle));
    }
}
