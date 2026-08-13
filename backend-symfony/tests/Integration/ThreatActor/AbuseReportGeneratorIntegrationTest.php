<?php

declare(strict_types=1);

namespace App\Tests\Integration\ThreatActor;

use App\Application\Clustering\ClusterQueryService;
use App\Application\Clustering\ClusterTemporalAnalyzer;
use App\Application\ThreatActor\AbuseReportGenerator;
use App\Application\ThreatActor\ThreatActorPsychProfileReaderInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for the abuse-report assembler: it must weave the cluster
 * detail (anchor IOCs), the temporal analysis and the psych profile into one
 * factual report, routing each actionable indicator to its abuse desk.
 */
class AbuseReportGeneratorIntegrationTest extends KernelTestCase
{
    private const CID = 'aaaaaaaa-0000-4000-8000-0000000000cb';
    private const IBAN_INDICATOR = 'aaaaaaaa-0002-4000-8000-000000000002';
    private const URL_INDICATOR = 'aaaaaaaa-0001-4000-8000-000000000001';
    private const CONV = '00000000-0000-0000-0000-000000000002';

    private AbuseReportGenerator $generator;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->conn = $em->getConnection();

        $psychReader = $container->get(ThreatActorPsychProfileReaderInterface::class);
        \assert($psychReader instanceof ThreatActorPsychProfileReaderInterface);

        $this->generator = new AbuseReportGenerator(
            new ClusterQueryService($this->conn),
            new ClusterTemporalAnalyzer($em),
            $psychReader,
        );
    }

    public function testGenerateAssemblesFactualReportWithRoutedIndicators(): void
    {
        try {
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Abuse Report Test Actor'],
            );
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_ioc (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
                 VALUES (:cid, :ind, :type, :hash, 1, :ts, :ts)',
                [
                    'cid' => self::CID,
                    'ind' => self::IBAN_INDICATOR,
                    'type' => 'iban',
                    'hash' => str_repeat('a', 64),
                    'ts' => '2026-06-01 00:00:00+00',
                ],
            );
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CID, 'conv' => self::CONV],
            );
            // Give the linked conversation a known engaged duration (1h, >= 2 turns) so the
            // rolled-up "criminal time wasted" is deterministic. DAMA rolls this back.
            $this->conn->executeStatement(
                'UPDATE conversation SET engagement_duration_sec = 3600, turns_count = 4 WHERE conv_id = :conv',
                ['conv' => self::CONV],
            );
            // An IBAN is a financial indicator: the export policy holds it until an
            // analyst confirms it, so an actionable report carries a confirmed one.
            $this->setVerdict('confirmed');

            $report = $this->generator->generate(self::CID);

            self::assertIsArray($report);
            self::assertSame('threat-actor-abuse-report', $report['report_type']);
            self::assertSame('Abuse Report Test Actor', $report['actor']['name']);

            // The IBAN anchor became an actionable indicator routed to a bank.
            self::assertSame(1, $report['evidence']['actionable_indicator_count']);
            $indicators = $report['actionable_indicators'];
            self::assertCount(1, $indicators);
            self::assertSame('iban', $indicators[0]['type']);
            self::assertStringContainsStringIgnoringCase('bank', $indicators[0]['recommended_recipient']);

            // Temporal woven in (conv has an inbound message) + a ready-to-send text body.
            self::assertIsArray($report['temporal']);
            self::assertGreaterThanOrEqual(1, $report['evidence']['inbound_message_count']);
            self::assertIsString($report['text']);
            self::assertStringContainsString('ABUSE / TAKEDOWN REPORT', $report['text']);
            self::assertStringContainsString('DISCLAIMER', $report['text']);

            // Criminal time wasted: the 1h engaged conversation is rolled up into the evidence + text.
            self::assertSame(3600, $report['evidence']['criminal_time_wasted_sec']);
            self::assertStringContainsString('Criminal time wasted: 1.0 hours', $report['text']);
        } finally {
            $this->cleanupCluster();
        }
    }

    public function testGenerateReturnsNullForUnknownCluster(): void
    {
        self::assertNull($this->generator->generate('ffffffff-ffff-ffff-ffff-ffffffffffff'));
    }

    /**
     * Seed a single-anchor cluster around the IBAN indicator, with an optional
     * analyst verdict. Returns the query service so callers can compare the
     * outgoing report against the internal detail view.
     */
    private function seedSingleAnchorCluster(?string $verdict): ClusterQueryService
    {
        $this->conn->executeStatement(
            'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
            ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Export Policy Test Actor'],
        );
        $this->conn->executeStatement(
            'INSERT INTO threat_actor_cluster_ioc (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
             VALUES (:cid, :ind, :type, :hash, 1, :ts, :ts)',
            [
                'cid' => self::CID,
                'ind' => self::IBAN_INDICATOR,
                'type' => 'iban',
                'hash' => str_repeat('b', 64),
                'ts' => '2026-06-01 00:00:00+00',
            ],
        );
        $this->conn->executeStatement(
            'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
            ['cid' => self::CID, 'conv' => self::CONV],
        );

        if ($verdict !== null) {
            $this->setVerdict($verdict);
        }

        return new ClusterQueryService($this->conn);
    }

    private function setVerdict(string $verdict, string $indicatorId = self::IBAN_INDICATOR): void
    {
        $this->conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, :v, 'export policy test', 'export-policy-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = :v",
            ['id' => $indicatorId, 'v' => $verdict],
        );
    }

    private function addAnchor(string $indicatorId, string $type): void
    {
        $this->conn->executeStatement(
            'INSERT INTO threat_actor_cluster_ioc (cluster_id, indicator_id, ioc_type, value_norm_hash, conv_count, first_observed, last_observed)
             VALUES (:cid, :ind, :type, :hash, 1, :ts, :ts)',
            [
                'cid' => self::CID,
                'ind' => $indicatorId,
                'type' => $type,
                'hash' => substr(hash('sha256', $indicatorId), 0, 64),
                'ts' => '2026-06-01 00:00:00+00',
            ],
        );
    }

    private function indicatorValue(string $indicatorId): string
    {
        return (string) $this->conn->fetchOne('SELECT value FROM indicator WHERE indicator_id = ?', [$indicatorId]);
    }

    private function cleanupCluster(): void
    {
        $this->conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
        $this->conn->executeStatement(
            'DELETE FROM ioc_analyst_feedback WHERE indicator_id IN (?, ?)',
            [self::IBAN_INDICATOR, self::URL_INDICATOR],
        );
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function reportedValues(array $report): array
    {
        $values = [];

        foreach ($report['actionable_indicators'] as $indicator) {
            $values[] = (string) $indicator['value'];
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function detailIndicatorIds(ClusterQueryService $query): array
    {
        $detail = $query->getDetail(self::CID);
        self::assertIsArray($detail);
        $ids = [];

        foreach ($detail['anchor_iocs'] as $anchor) {
            $ids[] = (string) $anchor['indicator_id'];
        }

        return $ids;
    }

    /**
     * An analyst verdict of false positive must suppress the indicator from the
     * outgoing abuse report — it is sent to a bank or a national unit, and a
     * discarded indicator cannot be unsent. The verdict is the only variable:
     * the same indicator is confirmed first, so its disappearance can only be
     * attributed to the verdict flip.
     *
     * The internal cluster detail must keep showing it: reviewers have to see
     * what they rejected in order to revise it.
     */
    public function testAnalystFalsePositiveIsSuppressedFromTheReportButStaysVisibleInternally(): void
    {
        try {
            $query = $this->seedSingleAnchorCluster('confirmed');

            $confirmedReport = $this->generator->generate(self::CID);
            self::assertIsArray($confirmedReport);
            self::assertCount(1, $confirmedReport['actionable_indicators'], 'A confirmed indicator belongs in the report');

            $this->setVerdict('false_positive');

            $report = $this->generator->generate(self::CID);
            self::assertIsArray($report);
            self::assertSame([], $this->reportedValues($report), 'A rejected indicator must never reach an external recipient');

            // The ready-to-send body is the surface that actually leaves the platform,
            // so assert on it directly rather than trusting the structured list alone.
            self::assertIsString($report['text']);
            self::assertStringNotContainsString(
                $this->indicatorValue(self::IBAN_INDICATOR),
                $report['text'],
                'The rejected value must not survive anywhere in the sendable body'
            );

            // Producing no indicators is a valid outcome, not an error.
            self::assertSame(0, $report['evidence']['actionable_indicator_count']);

            // The internal review surface is unaffected.
            self::assertContains(
                self::IBAN_INDICATOR,
                $this->detailIndicatorIds($query),
                'Reviewers must still see the indicator they rejected'
            );
        } finally {
            $this->cleanupCluster();
        }
    }

    /**
     * The export policy holds financial indicators that no analyst confirmed —
     * accusing a bank account on unverified evidence is the harm this guard
     * exists to prevent. The abuse-report path must honour it like every other
     * outgoing path.
     */
    public function testUnconfirmedFinancialAnchorIsHeldFromTheReport(): void
    {
        try {
            $query = $this->seedSingleAnchorCluster(null);

            $report = $this->generator->generate(self::CID);
            self::assertIsArray($report);
            self::assertSame([], $this->reportedValues($report), 'An unconfirmed financial indicator must be held from export');
            self::assertStringNotContainsString(
                $this->indicatorValue(self::IBAN_INDICATOR),
                (string) $report['text'],
                'A withheld account number must not appear in the sendable body either'
            );

            self::assertContains(self::IBAN_INDICATOR, $this->detailIndicatorIds($query));
        } finally {
            $this->cleanupCluster();
        }
    }

    /**
     * Both outgoing paths describe the same actor to the same outside world, so
     * they must never disagree about which indicators may leave.
     *
     * The cluster deliberately mixes verdicts — one confirmed anchor and one
     * rejected anchor — so the assertion discriminates. Comparing two paths on a
     * cluster where everything is allowed would pass for any filter, including a
     * wrong one, or none at all.
     */
    public function testReportIndicatorSetMatchesTheStixExportSet(): void
    {
        try {
            $query = $this->seedSingleAnchorCluster('confirmed');
            $this->addAnchor(self::URL_INDICATOR, 'url');
            $this->setVerdict('false_positive', self::URL_INDICATOR);

            $report = $this->generator->generate(self::CID);
            self::assertIsArray($report);

            $stix = $query->getStixExportData(self::CID);
            self::assertIsArray($stix);
            $stixValues = array_map(
                static fn (array $row): string => (string) $row['value'],
                $stix['indicator_data'],
            );

            sort($stixValues);
            $reported = $this->reportedValues($report);
            sort($reported);

            self::assertSame($stixValues, $reported, 'Abuse report and STIX export must expose the same indicators');

            // Both paths kept the confirmed anchor and dropped the rejected one — so the
            // agreement above is agreement on a real filter, not on an empty or total set.
            self::assertSame([$this->indicatorValue(self::IBAN_INDICATOR)], $reported);
            self::assertNotContains($this->indicatorValue(self::URL_INDICATOR), $reported);

            // And the internal view still shows both, rejected one included.
            $detailIds = $this->detailIndicatorIds($query);
            self::assertContains(self::IBAN_INDICATOR, $detailIds);
            self::assertContains(self::URL_INDICATOR, $detailIds);
        } finally {
            $this->cleanupCluster();
        }
    }

    public function testEngagementDurationSumsOnlyEngagedLiveConversations(): void
    {
        $query = new ClusterQueryService($this->conn);

        try {
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)',
                ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Duration Test'],
            );
            $this->conn->executeStatement(
                'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                ['cid' => self::CID, 'conv' => self::CONV],
            );

            // Engaged (>= 2 turns) + live → counted.
            $this->conn->executeStatement(
                'UPDATE conversation SET engagement_duration_sec = 1800, turns_count = 3, deleted_at = NULL WHERE conv_id = :c',
                ['c' => self::CONV],
            );
            self::assertSame(1800, $query->getEngagementDurationSec(self::CID));

            // Single-turn (< 2) → excluded (no real back-and-forth).
            $this->conn->executeStatement('UPDATE conversation SET turns_count = 1 WHERE conv_id = :c', ['c' => self::CONV]);
            self::assertSame(0, $query->getEngagementDurationSec(self::CID));

            // Soft-deleted → excluded.
            $this->conn->executeStatement("UPDATE conversation SET turns_count = 3, deleted_at = NOW() WHERE conv_id = :c", ['c' => self::CONV]);
            self::assertSame(0, $query->getEngagementDurationSec(self::CID));

            // Unknown cluster → 0.
            self::assertSame(0, $query->getEngagementDurationSec('ffffffff-ffff-ffff-ffff-ffffffffffff'));
        } finally {
            $this->conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
        }
    }
}
