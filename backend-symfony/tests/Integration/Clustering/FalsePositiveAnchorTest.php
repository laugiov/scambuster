<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use App\Application\Clustering\IocClusteringService;
use App\Tests\Fixtures\ClusteringFixtures;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An analyst false-positive verdict must remove an IOC from the clustering
 * anchor set: a mis-extracted or benign identifier (e.g. a legitimate vendor
 * IBAN) that keeps merging conversations builds wrong actor attribution, even
 * though its value no longer exports.
 */
final class FalsePositiveAnchorTest extends KernelTestCase
{
    private Connection $conn;
    private IocClusteringService $service;

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

    private function getClusterForConv(string $convId): ?string
    {
        $id = $this->conn->fetchOne(
            'SELECT cluster_id FROM threat_actor_cluster_conversation WHERE conv_id = :id',
            ['id' => $convId],
        );

        return \is_string($id) ? $id : null;
    }

    public function testFalsePositiveAnchorDoesNotMergeConversations(): void
    {
        // The cluster-A conversations share IBAN GB82WEST12345698765432 (fixtures).
        $indicatorId = $this->conn->fetchOne(
            "SELECT indicator_id FROM indicator WHERE value_norm = 'GB82WEST12345698765432'",
        );
        self::assertIsString($indicatorId, 'fixtures must provide the shared IBAN indicator');

        // An analyst marks the shared IBAN as a false positive…
        $this->conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, 'false_positive', 'benign vendor account', 'fp-anchor-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'false_positive'",
            ['id' => $indicatorId],
        );

        // …so clustering must no longer use it as a merge anchor.
        $this->service->clusterConversation($this->convA(1));

        self::assertNull(
            $this->getClusterForConv($this->convA(1)),
            'a false-positive anchor must not merge conversations into a cluster',
        );
    }

    public function testConfirmedAnchorStillMerges(): void
    {
        // Control: a confirmed verdict must not break clustering.
        $indicatorId = $this->conn->fetchOne(
            "SELECT indicator_id FROM indicator WHERE value_norm = 'GB82WEST12345698765432'",
        );
        self::assertIsString($indicatorId);

        $this->conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, 'confirmed', NULL, 'fp-anchor-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'confirmed'",
            ['id' => $indicatorId],
        );

        $this->service->clusterConversation($this->convA(1));

        self::assertNotNull(
            $this->getClusterForConv($this->convA(1)),
            'a confirmed anchor must keep clustering as before',
        );
    }
}
