<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for cluster creation.
 *
 * Note: Fixtures load all conversations at once, so clusterConversation()
 * finds ALL conversations that share anchor IOCs — not just "previously processed" ones.
 * This matches production behavior: the service clusters based on current DB state.
 */
class ClusterCreationTest extends KernelTestCase
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

    public function testClusterCreatedWhenSharedAnchorIocsExist(): void
    {
        // All 5 cluster-A convs share IBAN GB82WEST12345698765432
        // Processing a1 finds a2-a5 sharing the IBAN → creates cluster with all 5
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $this->assertNotNull($clusterId, 'Conv a1 should be in a cluster (shares IBAN with a2-a5)');

        // All 5 convs should be in the same cluster
        for ($i = 2; $i <= 5; $i++) {
            $otherClusterId = $this->getClusterForConv($this->convA($i));
            $this->assertSame($clusterId, $otherClusterId, "Conv a{$i} should be in the same cluster as a1");
        }
    }

    public function testClusterHasCorrectConversationCount(): void
    {
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $this->assertNotNull($clusterId);

        $count = $this->conn->fetchOne(
            'SELECT conversation_count FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterId]
        );
        $this->assertSame(5, (int) $count, 'Cluster A should have 5 conversations');
    }

    public function testClusterHasStixId(): void
    {
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $stixId = $this->conn->fetchOne(
            'SELECT stix_id FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $this->assertNotNull($stixId);
        $this->assertStringStartsWith('threat-actor--', (string) $stixId);
    }

    public function testClusterHasAnchorIocs(): void
    {
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $anchors = $this->conn->fetchAllAssociative(
            'SELECT ioc_type, value_norm_hash FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $this->assertGreaterThan(0, count($anchors), 'Cluster should have anchor IOCs');
        $this->assertSame('iban', $anchors[0]['ioc_type']);
        $this->assertSame(64, strlen($anchors[0]['value_norm_hash']), 'Should be SHA-256 hash');
    }

    public function testClusterNameFormat(): void
    {
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $name = $this->conn->fetchOne(
            'SELECT name FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $this->assertIsString($name);
        $this->assertStringContainsString('ScamBuster Cluster', (string) $name);
        $this->assertStringContainsString('5 conversations', (string) $name);
    }

    public function testClusterStatusActive(): void
    {
        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        $status = $this->conn->fetchOne(
            'SELECT status FROM threat_actor_cluster WHERE cluster_id = :id',
            ['id' => $clusterId]
        );

        $this->assertSame('active', $status);
    }

    public function testSingletonDoesNotCreateCluster(): void
    {
        // conv-single-01 has only MEDIUM IOCs (domain)
        $singletonId = sprintf('cccccccc-5555-4000-8000-%012d', 1);
        $this->service->clusterConversation($singletonId);

        $clusterId = $this->getClusterForConv($singletonId);
        $this->assertNull($clusterId, 'Singleton should not be in a cluster');
    }

    public function testNoIocConversationDoesNotCreateCluster(): void
    {
        $noIocId = sprintf('cccccccc-0000-4000-8000-%012d', 1);
        $this->service->clusterConversation($noIocId);

        $clusterId = $this->getClusterForConv($noIocId);
        $this->assertNull($clusterId, 'No-IOC conv should not be in a cluster');
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
