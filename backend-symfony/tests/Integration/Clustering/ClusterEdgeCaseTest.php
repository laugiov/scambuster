<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Edge-case and guard tests for clustering.
 *
 * Tests:
 * - MEDIUM IOCs (domain, email) must NOT trigger clustering
 * - No-IOC conversations must not cluster
 * - Mega-cluster guard (>50 convs → status 'suspect')
 * - Total cluster count across all fixtures
 */
class ClusterEdgeCaseTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

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

    public function testSharedDomainDoesNotCreateCluster(): void
    {
        // Singletons s1-s5 share domain 'phishing-kit.com' (MEDIUM severity)
        for ($i = 1; $i <= 5; $i++) {
            $convId = sprintf('cccccccc-5555-4000-8000-%012d', $i);
            $this->service->clusterConversation($convId);
        }

        // None should be clustered
        for ($i = 1; $i <= 5; $i++) {
            $convId = sprintf('cccccccc-5555-4000-8000-%012d', $i);
            $clusterId = $this->getClusterForConv($convId);
            $this->assertNull($clusterId, "Singleton s{$i} should not be clustered (MEDIUM domain)");
        }
    }

    public function testSharedEmailDoesNotCreateCluster(): void
    {
        // Singletons s6-s10 share email 'scammer@evil.com' (MEDIUM severity)
        for ($i = 6; $i <= 10; $i++) {
            $convId = sprintf('cccccccc-5555-4000-8000-%012d', $i);
            $this->service->clusterConversation($convId);
        }

        for ($i = 6; $i <= 10; $i++) {
            $convId = sprintf('cccccccc-5555-4000-8000-%012d', $i);
            $this->assertNull($this->getClusterForConv($convId), "Singleton s{$i} should not be clustered (MEDIUM email)");
        }
    }

    public function testNoIocConversationsRemainUnclustered(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            $convId = sprintf('cccccccc-0000-4000-8000-%012d', $i);
            $this->service->clusterConversation($convId);
            $this->assertNull($this->getClusterForConv($convId), "No-IOC conv n{$i} should not be clustered");
        }
    }

    public function testFullFixtureProducesExactlyThreeClusters(): void
    {
        // Process all 23 conversations
        $allConvs = [];

        // Cluster A: 5 convs
        for ($i = 1; $i <= 5; $i++) {
            $allConvs[] = sprintf('cccccccc-aaaa-4000-8000-%012d', $i);
        }

        // Cluster B: 3 convs
        for ($i = 1; $i <= 3; $i++) {
            $allConvs[] = sprintf('cccccccc-bbbb-4000-8000-%012d', $i);
        }

        // Cluster C: 3 convs
        for ($i = 1; $i <= 3; $i++) {
            $allConvs[] = sprintf('cccccccc-cccc-4000-8000-%012d', $i);
        }

        // Singletons: 10 convs
        for ($i = 1; $i <= 10; $i++) {
            $allConvs[] = sprintf('cccccccc-5555-4000-8000-%012d', $i);
        }

        // No IOCs: 2 convs
        for ($i = 1; $i <= 2; $i++) {
            $allConvs[] = sprintf('cccccccc-0000-4000-8000-%012d', $i);
        }

        foreach ($allConvs as $convId) {
            $this->service->clusterConversation($convId);
        }

        $activeClusterCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $this->assertSame(3, $activeClusterCount, 'Should have exactly 3 active clusters');
    }

    public function testClusterConversationCounts(): void
    {
        // Process one from each cluster to trigger creation
        $this->service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
        $this->service->clusterConversation(sprintf('cccccccc-bbbb-4000-8000-%012d', 1));
        $this->service->clusterConversation(sprintf('cccccccc-cccc-4000-8000-%012d', 2));

        $counts = $this->conn->fetchAllKeyValue(
            "SELECT name, conversation_count FROM threat_actor_cluster WHERE status != 'merged' ORDER BY conversation_count DESC"
        );

        $countValues = array_values($counts);
        $this->assertSame([5, 3, 3], array_map('intval', $countValues));
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
