<?php

declare(strict_types=1);

namespace App\Tests\Functional\Clustering;

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

    // === Spec 096 / C4 — scam_type filter on cluster stats ===

    public function testClusterStatsAcceptsScamTypeFilter_096C4(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats?scam_type=INVOICE_FRAUD', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('total_clusters', $data);
        $this->assertGreaterThanOrEqual(0, $data['total_clusters']);
    }

    public function testClusterStatsScamTypeFilterReducesOrEquals_096C4(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $baseline = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/v1/clusters/stats?scam_type=PHISHING', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $filtered = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Filter NEVER returns more clusters than the unfiltered set
        $this->assertLessThanOrEqual($baseline['total_clusters'], $filtered['total_clusters']);
        $this->assertLessThanOrEqual($baseline['clustered_conversations'], $filtered['clustered_conversations']);
    }

    public function testClusterStatsEmptyScamTypeBehavesAsNoFilter_096C4(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $baseline = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/v1/clusters/stats?scam_type=', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $withEmpty = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame($baseline['total_clusters'], $withEmpty['total_clusters']);
    }

    // === Spec 096 / C5 — period filter on cluster stats (conv counts only) ===

    public function testClusterStatsAcceptsPeriodFilter_096C5(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats?period=7d', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('total_conversations', $data);
        $this->assertGreaterThanOrEqual(0, $data['total_conversations']);
    }

    public function testClusterStatsPeriodReducesOrEqualsConvCounts_096C5(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $baseline = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/v1/clusters/stats?period=7d', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $filtered = json_decode((string) $this->client->getResponse()->getContent(), true);

        // A 7-day window NEVER has more conversations than the unfiltered set
        $this->assertLessThanOrEqual($baseline['total_conversations'], $filtered['total_conversations']);
        $this->assertLessThanOrEqual($baseline['clustered_conversations'], $filtered['clustered_conversations']);
        // total_clusters is UNFILTERED by period per design — must remain equal
        $this->assertSame($baseline['total_clusters'], $filtered['total_clusters']);
    }

    public function testClusterStatsPeriodAndScamTypeCombine_096C5(): void
    {
        $this->client->request('GET', '/api/v1/clusters/stats?period=30d&scam_type=PHISHING', [], [], ['HTTP_AUTHORIZATION' => 'Bearer fake-jwt']);
        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('total_conversations', $data);
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

    public function testClusterDetailAnchorIocDatesReflectRealObservations(): void
    {
        // Cluster A has 5 observations of the IBAN on days -5 to -1 (see ClusteringFixtures).
        // first_observed should be day -5, last_observed should be day -1.
        // Bug: previously both were set to cluster creation time.
        $clusterId = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status = 'active' ORDER BY conversation_count DESC LIMIT 1"
        );

        $this->client->request('GET', "/api/v1/clusters/{$clusterId}", [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-jwt',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('anchor_iocs', $data);
        $this->assertNotEmpty($data['anchor_iocs']);

        $ibanAnchor = null;

        foreach ($data['anchor_iocs'] as $anchor) {
            if (($anchor['ioc_type'] ?? null) === 'iban') {
                $ibanAnchor = $anchor;
                break;
            }
        }

        $this->assertNotNull($ibanAnchor, 'Cluster A must expose an iban anchor');

        $firstObserved = new \DateTimeImmutable((string) $ibanAnchor['first_observed']);
        $lastObserved = new \DateTimeImmutable((string) $ibanAnchor['last_observed']);

        // The fixture spans 4 days (day -5 to day -1), so last - first ≈ 4 days
        $diffHours = ($lastObserved->getTimestamp() - $firstObserved->getTimestamp()) / 3600;
        $this->assertGreaterThan(48, $diffHours, 'first_observed and last_observed must differ by more than 48 hours (fixture spans 4 days)');

        // first_observed must predate the cluster creation (created today)
        $createdAt = $this->conn->fetchOne('SELECT created_at FROM threat_actor_cluster WHERE cluster_id = ?', [$clusterId]);
        $createdAtDt = new \DateTimeImmutable((string) $createdAt);
        $this->assertLessThan($createdAtDt->getTimestamp(), $firstObserved->getTimestamp(), 'first_observed must be before cluster creation time');
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
