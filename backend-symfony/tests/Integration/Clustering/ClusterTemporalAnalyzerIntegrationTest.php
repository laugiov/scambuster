<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\ClusterTemporalAnalyzer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for the DBAL side of the temporal analyzer: the cluster ->
 * conversation -> inbound-message join, direction resolution via the lookup code,
 * and the null-on-empty contract. The metric maths itself is unit-tested.
 */
class ClusterTemporalAnalyzerIntegrationTest extends KernelTestCase
{
    private const CID = 'aaaaaaaa-0000-4000-8000-0000000000c9';
    private const CONV_A = '00000000-0000-0000-0000-000000000002';
    private const CONV_B = '00000000-0000-0000-0000-000000000003';

    private ClusterTemporalAnalyzer $analyzer;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->conn = $em->getConnection();
        $this->analyzer = new ClusterTemporalAnalyzer($em);
    }

    public function testAnalyzeAggregatesInboundMessagesOfClusterConversations(): void
    {
        try {
            $this->conn->executeStatement(
                "INSERT INTO threat_actor_cluster (cluster_id, stix_id, name) VALUES (:cid, :stix, :name)",
                ['cid' => self::CID, 'stix' => 'threat-actor--' . self::CID, 'name' => 'Temporal Test Actor'],
            );

            foreach ([self::CONV_A, self::CONV_B] as $conv) {
                $this->conn->executeStatement(
                    'INSERT INTO threat_actor_cluster_conversation (cluster_id, conv_id) VALUES (:cid, :conv)',
                    ['cid' => self::CID, 'conv' => $conv],
                );
            }

            $m = $this->analyzer->analyze(self::CID);

            self::assertIsArray($m);
            // Two linked conversations, one inbound message each.
            self::assertSame(2, $m['message_count']);
            self::assertGreaterThanOrEqual(1, $m['active_days']);
            self::assertNotNull($m['first_activity']);
            self::assertNotNull($m['last_activity']);
            self::assertGreaterThanOrEqual(0, $m['peak_hour']);
            self::assertLessThanOrEqual(23, $m['peak_hour']);
            // Two messages cannot cross the burst floor (3).
            self::assertSame(0, $m['burst_count']);
        } finally {
            // Cascade removes the join rows; DAMA also rolls the test back.
            $this->conn->executeStatement('DELETE FROM threat_actor_cluster WHERE cluster_id = ?', [self::CID]);
        }
    }

    public function testAnalyzeReturnsNullForUnknownCluster(): void
    {
        self::assertNull($this->analyzer->analyze('ffffffff-ffff-ffff-ffff-ffffffffffff'));
    }
}
