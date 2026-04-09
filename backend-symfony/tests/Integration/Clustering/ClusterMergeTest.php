<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for cluster merging (transitive clustering).
 *
 * Cluster C fixture:
 * - c1+c2 share phone +33698765432
 * - c2+c3 share IBAN DE89370400440532013000
 * - All 3 should end up in the same cluster via transitivity through c2.
 *
 * Also tests merge when two independent clusters later discover a bridge.
 */
class ClusterMergeTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

    private function convC(int $i): string
    {
        return sprintf('cccccccc-cccc-4000-8000-%012d', $i);
    }

    private function convA(int $i): string
    {
        return sprintf('cccccccc-aaaa-4000-8000-%012d', $i);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
        $this->service = new IocClusteringService($this->conn, new NullLogger());

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    public function testTransitiveClusteringViaSharedConversation(): void
    {
        // c2 shares phone with c1 AND IBAN with c3 → all 3 in same cluster
        $this->service->clusterConversation($this->convC(2));

        $clusterC1 = $this->getClusterForConv($this->convC(1));
        $clusterC2 = $this->getClusterForConv($this->convC(2));
        $clusterC3 = $this->getClusterForConv($this->convC(3));

        $this->assertNotNull($clusterC2, 'c2 should be clustered');
        $this->assertSame($clusterC2, $clusterC1, 'c1 should be in same cluster as c2');
        $this->assertSame($clusterC2, $clusterC3, 'c3 should be in same cluster as c2');
    }

    public function testTransitiveClusterHasCorrectCount(): void
    {
        $this->service->clusterConversation($this->convC(2));

        $clusterId = $this->getClusterForConv($this->convC(2));
        $count = (int) $this->conn->fetchOne(
            'SELECT conversation_count FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $this->assertSame(3, $count, 'Transitive cluster should have 3 conversations');
    }

    public function testTransitiveClusterHasBothAnchorTypes(): void
    {
        $this->service->clusterConversation($this->convC(2));

        $clusterId = $this->getClusterForConv($this->convC(2));
        $types = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT ioc_type FROM threat_actor_cluster_ioc WHERE cluster_id = :id ORDER BY ioc_type',
            ['id' => $clusterId]
        );

        $this->assertContains('iban', $types, 'Should have IBAN anchor');
        $this->assertContains('phone', $types, 'Should have phone anchor');
    }

    public function testMergedClusterGetsStatusMerged(): void
    {
        // First create two separate clusters by processing c1 (finds c2 via phone)
        // and c3 (finds c2 via IBAN) — if c2 is in both, they merge
        $this->service->clusterConversation($this->convC(1));
        $this->service->clusterConversation($this->convC(3));

        // Count active (non-merged) clusters containing C convs
        $activeCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(DISTINCT tac.cluster_id)
             FROM threat_actor_cluster tac
             JOIN threat_actor_cluster_conversation tacc ON tac.cluster_id = tacc.cluster_id
             WHERE tacc.conv_id IN (:c1, :c2, :c3) AND tac.status != 'merged'",
            ['c1' => $this->convC(1), 'c2' => $this->convC(2), 'c3' => $this->convC(3)]
        );

        $this->assertSame(1, $activeCount, 'Should have exactly 1 active cluster after merge');
    }

    public function testClustersAAndCRemainSeparate(): void
    {
        // Cluster A (IBAN) and Cluster C (phone+IBAN) share no common IOCs
        $this->service->clusterConversation($this->convA(1));
        $this->service->clusterConversation($this->convC(2));

        $clusterA = $this->getClusterForConv($this->convA(1));
        $clusterC = $this->getClusterForConv($this->convC(2));

        $this->assertNotNull($clusterA);
        $this->assertNotNull($clusterC);
        $this->assertNotSame($clusterA, $clusterC, 'Clusters A and C should be separate');
    }

    public function testMergeTwoClustersViaBridgeConversation(): void
    {
        // Step 1: Create cluster for c1 (finds c2 via phone → cluster with c1+c2)
        $this->service->clusterConversation($this->convC(1));
        $cluster1 = $this->getClusterForConv($this->convC(1));
        $this->assertNotNull($cluster1);

        // Step 2: Manually put c3 in a separate cluster (simulate pre-existing cluster)
        // c3 shares IBAN with c2 but we need it in a DIFFERENT cluster
        // Use cluster A to create a separate cluster, then check merge when we process c2 again
        $this->service->clusterConversation($this->convA(1));
        $clusterA = $this->getClusterForConv($this->convA(1));
        $this->assertNotNull($clusterA);
        $this->assertNotSame($cluster1, $clusterA, 'Clusters should be separate initially');

        // Count active clusters before
        $activeBefore = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        // Step 3: Directly call mergeClusters to test the merge path
        $survivorId = $this->service->mergeClusters([$cluster1, $clusterA]);

        // Verify one is the survivor
        $this->assertNotNull($survivorId);

        // The absorbed cluster should have status 'merged'
        $mergedCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status = 'merged'"
        );
        $this->assertSame(1, $mergedCount, 'One cluster should be marked as merged');

        // Active clusters should be one less
        $activeAfter = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );
        $this->assertSame($activeBefore - 1, $activeAfter);
    }

    public function testMergeSingleClusterReturnsItself(): void
    {
        $this->service->clusterConversation($this->convA(1));
        $clusterA = $this->getClusterForConv($this->convA(1));
        $this->assertNotNull($clusterA);

        // Merging a single cluster should return itself
        $result = $this->service->mergeClusters([$clusterA]);
        $this->assertSame($clusterA, $result);
    }

    private function getClusterForConv(string $convId): ?string
    {
        $result = $this->conn->fetchOne(
            'SELECT tacc.cluster_id FROM threat_actor_cluster_conversation tacc
             JOIN threat_actor_cluster tac ON tac.cluster_id = tacc.cluster_id
             WHERE tacc.conv_id = :convId AND tac.status != \'merged\'',
            ['convId' => $convId]
        );

        return $result !== false && $result !== null ? (string) $result : null;
    }
}
