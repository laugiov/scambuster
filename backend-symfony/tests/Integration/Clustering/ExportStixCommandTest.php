<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for the clustering STIX export command.
 */
class ExportStixCommandTest extends KernelTestCase
{
    private Connection $conn;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);

        $app = new Application(self::$kernel);
        $command = $app->find('app:clustering:export-stix');
        $this->tester = new CommandTester($command);

        // Load fixtures and create clusters
        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);

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

    public function testExportAllClusters(): void
    {
        $this->tester->execute([]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('threat-actor', $output);
        $this->assertStringContainsString('3', $output); // 3 threat-actors
    }

    public function testExportSingleCluster(): void
    {
        $clusterId = $this->conn->fetchOne(
            "SELECT cluster_id FROM threat_actor_cluster WHERE status = 'active' LIMIT 1"
        );

        $this->tester->execute(['--cluster-id' => $clusterId]);

        $this->assertSame(0, $this->tester->getStatusCode());
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('threat-actor', $output);
    }

    public function testExportToFile(): void
    {
        $tmpFile = sys_get_temp_dir() . '/scambuster-stix-export-test-' . uniqid() . '.json';

        try {
            $this->tester->execute(['--output' => $tmpFile]);

            $this->assertSame(0, $this->tester->getStatusCode());
            $this->assertFileExists($tmpFile);

            $json = json_decode((string) file_get_contents($tmpFile), true);
            $this->assertIsArray($json);
            $this->assertSame('bundle', $json['type']);
            $this->assertArrayHasKey('objects', $json);
            $this->assertGreaterThan(0, \count($json['objects']));
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testExportSinceFilter(): void
    {
        // Use a future date — no clusters should match
        $this->tester->execute(['--since' => '2030-01-01T00:00:00Z']);

        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('No clusters found', $this->tester->getDisplay());
    }
}
