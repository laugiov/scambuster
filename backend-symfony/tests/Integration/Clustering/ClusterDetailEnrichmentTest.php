<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for cluster detail enrichment (spec 059).
 *
 * Verifies sample_excerpts (Sprint 1) and behavioral_profile (Sprint 2)
 * aggregations from ioc_context.
 */
class ClusterDetailEnrichmentTest extends KernelTestCase
{
    private Connection $conn;
    private ClusterQueryService $queryService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->queryService = new ClusterQueryService($this->conn);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        // Cluster the conversations so we have a cluster to query
        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    private function getClusterAId(): string
    {
        $id = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status != 'merged' LIMIT 1"
        );
        $this->assertNotFalse($id, 'Cluster A should exist');

        return (string) $id;
    }

    public function testGetDetailIncludesSampleExcerpts(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $this->assertArrayHasKey('sample_excerpts', $detail);
        $this->assertIsArray($detail['sample_excerpts']);
    }

    public function testSampleExcerptsContainKnownFixtureValues(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array{text: string, occurrence_count: int, source_conv_id: string}> $excerpts */
        $excerpts = $detail['sample_excerpts'];

        $texts = array_column($excerpts, 'text');
        $this->assertContains('Wire transfer demanded urgently to avoid penalties', $texts);
    }

    public function testSampleExcerptsAreDistinct(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array{text: string, occurrence_count: int, source_conv_id: string}> $excerpts */
        $excerpts = $detail['sample_excerpts'];

        $texts = array_column($excerpts, 'text');
        $this->assertCount(\count(array_unique($texts)), $texts, 'Sample excerpts should be distinct after dedup');
    }

    public function testSampleExcerptsLimitedToFive(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $this->assertLessThanOrEqual(5, \count($detail['sample_excerpts']));
    }

    public function testSampleExcerptsHaveOccurrenceCountAndSourceConvId(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array{text: string, occurrence_count: int, source_conv_id: string}> $excerpts */
        $excerpts = $detail['sample_excerpts'];

        $this->assertNotEmpty($excerpts);

        // The fixture inserts the same excerpt 3 times for cluster A
        // → after dedup, that excerpt should have occurrence_count = 3
        $repeated = array_filter($excerpts, fn (array $e) => $e['text'] === 'Wire transfer demanded urgently to avoid penalties');
        $this->assertCount(1, $repeated, 'Repeated excerpt should appear only once after dedup');

        $repeatedRow = array_values($repeated)[0];
        $this->assertSame(3, $repeatedRow['occurrence_count']);
        $this->assertNotEmpty($repeatedRow['source_conv_id']);
    }

    // ─── Sprint 2: Behavioral Profile aggregation ───

    public function testGetDetailIncludesBehavioralProfile(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $this->assertArrayHasKey('behavioral_profile', $detail);
    }

    public function testBehavioralProfileDominantStimulus(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertNotNull($profile);
        $this->assertSame('urgency-pressure', $profile['dominant_stimulus']);
    }

    public function testBehavioralProfileDominantStimulusCount(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        // Cluster A has 5 enriched ioc_context rows, all urgency-pressure
        $this->assertSame(5, $profile['dominant_stimulus_count']);
    }

    public function testBehavioralProfileAvgUrgency(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertEqualsWithDelta(0.80, $profile['avg_urgency_score'], 0.001);
    }

    public function testBehavioralProfileDominantRevelationTurn(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertSame(1, $profile['dominant_revelation_turn']);
    }

    public function testBehavioralProfileHesitationCount(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertSame(0, $profile['hesitation_count']);
    }

    public function testBehavioralProfileLanguageSwitchCount(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertSame(0, $profile['language_switch_count']);
    }

    public function testBehavioralProfileTotalEnrichedIocs(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        $this->assertSame(5, $profile['total_enriched_iocs']);
    }

    public function testBehavioralProfileTemplatedExcerptCount(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $profile = $detail['behavioral_profile'];

        // Cluster A has 1 excerpt repeated 3 times → 1 templated
        $this->assertSame(1, $profile['templated_excerpt_count']);
    }

    public function testAnchorIocsHaveDominantSemanticRole(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array<string, mixed>> $anchors */
        $anchors = $detail['anchor_iocs'];

        $iban = array_filter($anchors, fn (array $a) => $a['ioc_type'] === 'iban');
        $this->assertNotEmpty($iban);
        $ibanRow = array_values($iban)[0];

        $this->assertSame('Payment Destination', $ibanRow['dominant_semantic_role']);
    }

    public function testAnchorIocsHaveDominantStimulus(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array<string, mixed>> $anchors */
        $anchors = $detail['anchor_iocs'];

        $iban = array_filter($anchors, fn (array $a) => $a['ioc_type'] === 'iban');
        $ibanRow = array_values($iban)[0];

        $this->assertSame('urgency-pressure', $ibanRow['dominant_stimulus']);
    }

    public function testAnchorIocsHaveAvgUrgency(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        /** @var list<array<string, mixed>> $anchors */
        $anchors = $detail['anchor_iocs'];

        $iban = array_filter($anchors, fn (array $a) => $a['ioc_type'] === 'iban');
        $ibanRow = array_values($iban)[0];

        $this->assertEqualsWithDelta(0.80, $ibanRow['avg_urgency_score'], 0.001);
    }

    public function testNonRegressionExistingDetailFields(): void
    {
        $detail = $this->queryService->getDetail($this->getClusterAId());

        $this->assertNotNull($detail);
        $this->assertArrayHasKey('cluster_id', $detail);
        $this->assertArrayHasKey('name', $detail);
        $this->assertArrayHasKey('anchor_iocs', $detail);
        $this->assertArrayHasKey('conversations', $detail);

        // Anchor IOCs still have ioc_value field
        $this->assertNotEmpty($detail['anchor_iocs']);
        $this->assertArrayHasKey('ioc_value', $detail['anchor_iocs'][0]);
    }
}
