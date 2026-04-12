<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Integration tests for cluster API endpoints.
 */
class ClusterApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

        // Create clusters from fixtures
        $service = new IocClusteringService($this->conn, new NullLogger());
        $service->clusterConversation(sprintf('cccccccc-aaaa-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-bbbb-4000-8000-%012d', 1));
        $service->clusterConversation(sprintf('cccccccc-cccc-4000-8000-%012d', 2));
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    public function testListClusters(): void
    {
        $this->client->request('GET', '/api/v1/clusters', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertCount(3, $data);

        // Verify structure
        $first = $data[0];
        $this->assertArrayHasKey('cluster_id', $first);
        $this->assertArrayHasKey('stix_id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('conversation_count', $first);
        $this->assertArrayHasKey('anchor_ioc_types', $first);
    }

    public function testClusterStats(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('total_clusters', $data);
        $this->assertArrayHasKey('clustered_conversations', $data);
        $this->assertArrayHasKey('taxii_noise_reduction_pct', $data);
        $this->assertSame(3, $data['total_clusters']);
        $this->assertSame(11, $data['clustered_conversations']); // 5+3+3
    }

    public function testClusterDetail(): void
    {
        $clusterId = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status = 'active' ORDER BY conversation_count DESC LIMIT 1"
        );

        $this->client->request('GET', "/api/v1/clusters/{$clusterId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('conversations', $data);
        $this->assertArrayHasKey('anchor_iocs', $data);
        $this->assertGreaterThan(0, \count($data['conversations']));
        $this->assertGreaterThan(0, \count($data['anchor_iocs']));
    }

    public function testClusterDetailNotFound(): void
    {
        $this->client->request('GET', '/api/v1/clusters/00000000-0000-0000-0000-000000000000', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testClusterExportStix(): void
    {
        $clusterId = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status = 'active' LIMIT 1"
        );

        $this->client->request('GET', "/api/v1/clusters/{$clusterId}/export/stix", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertSame('bundle', $data['type']);
        $this->assertArrayHasKey('objects', $data);
        $this->assertGreaterThan(0, \count($data['objects']));

        // Verify threat-actor object exists
        $types = array_column($data['objects'], 'type');
        $this->assertContains('threat-actor', $types);
    }

    public function testIocClusterLookupFound(): void
    {
        // Get an anchor indicator ID from cluster IOCs
        $indicatorId = $this->conn->fetchOne(
            'SELECT indicator_id FROM threat_actor_cluster_ioc LIMIT 1'
        );

        $this->client->request('GET', "/api/v1/iocs/{$indicatorId}/cluster", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('cluster_id', $data);
        $this->assertArrayHasKey('name', $data);
    }

    public function testIocClusterLookupNotFound(): void
    {
        $this->client->request('GET', '/api/v1/iocs/00000000-0000-0000-0000-999999999999/cluster', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }
}
