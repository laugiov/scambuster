<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for the clustering backfill command.
 */
class BackfillCommandTest extends KernelTestCase
{
    private Connection $conn;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);

        $app = new Application(self::$kernel);
        $command = $app->find('app:clustering:backfill');
        $this->tester = new CommandTester($command);

        ClusteringFixtures::cleanup($this->conn);
        ClusteringFixtures::load($this->conn);
    }

    protected function tearDown(): void
    {
        ClusteringFixtures::cleanup($this->conn);
        parent::tearDown();
    }

    public function testDryRunDoesNotPersist(): void
    {
        $clustersBefore = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $this->tester->execute(['--dry-run' => true]);

        $clustersAfter = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $this->assertSame($clustersBefore, $clustersAfter, 'Dry-run should not create clusters');
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Dry-run', $this->tester->getDisplay());
    }

    public function testFullRunCreatesExpectedClusters(): void
    {
        $this->tester->execute([]);

        $clusterCount = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $this->assertSame(3, $clusterCount, 'Backfill should create exactly 3 clusters');
        $this->assertSame(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('Backfill complete', $this->tester->getDisplay());
    }

    public function testBackfillIsIdempotent(): void
    {
        // First run
        $this->tester->execute([]);
        $clustersFirst = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        // Second run
        $this->tester->execute([]);
        $clustersSecond = (int) $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $this->assertSame($clustersFirst, $clustersSecond, 'Idempotent: same cluster count after re-run');
        $this->assertSame(3, $clustersSecond);
    }
}
