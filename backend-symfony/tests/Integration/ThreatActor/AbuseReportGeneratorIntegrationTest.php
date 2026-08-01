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
            $this->conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
        }
    }

    public function testGenerateReturnsNullForUnknownCluster(): void
    {
        self::assertNull($this->generator->generate('ffffffff-ffff-ffff-ffff-ffffffffffff'));
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
