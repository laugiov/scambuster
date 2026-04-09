<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for joining an existing cluster.
 *
 * Scenario: Process cluster A (creates cluster with 5 convs),
 * then process cluster B (creates separate cluster with 3 convs).
 * Verify they remain separate clusters.
 * Then verify re-processing a1 is idempotent (no duplicate cluster).
 */
class ClusterJoinTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

    private function convA(int $i): string
    {
        return sprintf('cccccccc-aaaa-4000-8000-%012d', $i);
    }

    private function convB(int $i): string
    {
        return sprintf('cccccccc-bbbb-4000-8000-%012d', $i);
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

    public function testTwoDifferentClustersRemainSeparate(): void
    {
        // Process cluster A
        $this->service->clusterConversation($this->convA(1));
        $clusterA = $this->getClusterForConv($this->convA(1));

        // Process cluster B
        $this->service->clusterConversation($this->convB(1));
        $clusterB = $this->getClusterForConv($this->convB(1));

        $this->assertNotNull($clusterA);
        $this->assertNotNull($clusterB);
        $this->assertNotSame($clusterA, $clusterB, 'Clusters A and B must be separate');
    }

    public function testClusterBHasCorrectCount(): void
    {
        $this->service->clusterConversation($this->convB(1));
        $clusterB = $this->getClusterForConv($this->convB(1));

        $count = $this->conn->fetchOne(
            'SELECT conversation_count FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterB]
        );

        $this->assertSame(3, (int) $count, 'Cluster B should have 3 conversations');
    }

    public function testReprocessingConversationIsIdempotent(): void
    {
        // Process a1 → creates cluster with 5 convs
        $this->service->clusterConversation($this->convA(1));
        $clusterBefore = $this->getClusterForConv($this->convA(1));

        $countBefore = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM threat_actor_cluster WHERE status != \'merged\''
        );

        // Re-process a1 → should be idempotent
        $this->service->clusterConversation($this->convA(1));
        $clusterAfter = $this->getClusterForConv($this->convA(1));

        $countAfter = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM threat_actor_cluster WHERE status != \'merged\''
        );

        $this->assertSame($clusterBefore, $clusterAfter, 'Cluster ID should not change');
        $this->assertSame($countBefore, $countAfter, 'No new clusters should be created');
    }

    public function testReprocessingDoesNotDuplicateConversations(): void
    {
        $this->service->clusterConversation($this->convA(1));

        // Re-process a2 (already in cluster from first call)
        $this->service->clusterConversation($this->convA(2));

        $convCount = (int) $this->conn->fetchOne(
            'SELECT conversation_count FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $this->getClusterForConv($this->convA(1))]
        );

        $this->assertSame(5, $convCount, 'Conversation count should remain 5');
    }

    public function testAllClusterBConvsInSameCluster(): void
    {
        $this->service->clusterConversation($this->convB(1));

        $clusterB1 = $this->getClusterForConv($this->convB(1));
        $clusterB2 = $this->getClusterForConv($this->convB(2));
        $clusterB3 = $this->getClusterForConv($this->convB(3));

        $this->assertNotNull($clusterB1);
        $this->assertSame($clusterB1, $clusterB2);
        $this->assertSame($clusterB1, $clusterB3);
    }

    public function testClusterBHasBtcAnchorIoc(): void
    {
        $this->service->clusterConversation($this->convB(1));
        $clusterB = $this->getClusterForConv($this->convB(1));

        $anchors = $this->conn->fetchAllAssociative(
            'SELECT ioc_type FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
            ['id' => $clusterB]
        );

        $types = array_column($anchors, 'ioc_type');
        $this->assertContains('wallet_btc', $types, 'Cluster B should have wallet_btc anchor');
    }

    private function getClusterForConv(string $convId): ?string
    {
        $result = $this->conn->fetchOne(
            'SELECT cluster_id FROM threat_actor_cluster_conversation WHERE conv_id = :convId',
            ['convId' => $convId]
        );

        return $result !== false ? (string) $result : null;
    }
}
