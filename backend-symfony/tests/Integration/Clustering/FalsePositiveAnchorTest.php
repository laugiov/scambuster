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

    public function testFalsePositiveAnchorIsNotPersistedInClusterMetadata(): void
    {
        // Give a1 and a2 a SECOND, valid anchor (a shared BTC wallet) so a
        // cluster still forms once the IBAN is a false positive.
        $walletId = 'cccccccc-ffff-4000-8000-000000000001';
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'wallet_btc', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq', NOW(), NOW(), 2, 'AMBER', NOW(), NOW())",
            ['id' => $walletId],
        );

        foreach ([1, 2] as $n => $i) {
            $msgId = $this->conn->fetchOne(
                'SELECT msg_id FROM message WHERE conv_id = :c LIMIT 1',
                ['c' => $this->convA($i)],
            );
            self::assertIsString($msgId, "fixtures must provide a message for conv a{$i}");
            $this->conn->executeStatement(
                "INSERT INTO observed_ioc (obs_id, msg_id, indicator_id, confidence_score, context_observation, ts_observed)
                 VALUES (:obs, :msg, :ind, 0.9, '{}', NOW())",
                ['obs' => sprintf('cccccccc-ffff-4000-8000-%012d', $n + 2), 'msg' => $msgId, 'ind' => $walletId],
            );
        }

        $ibanId = $this->conn->fetchOne(
            "SELECT indicator_id FROM indicator WHERE value_norm = 'GB82WEST12345698765432'",
        );
        self::assertIsString($ibanId);
        $this->conn->executeStatement(
            "INSERT INTO ioc_analyst_feedback (indicator_id, verdict, note, analyst_id, created_at)
             VALUES (:id, 'false_positive', NULL, 'fp-anchor-test', NOW())
             ON CONFLICT (indicator_id) DO UPDATE SET verdict = 'false_positive'",
            ['id' => $ibanId],
        );

        $this->service->clusterConversation($this->convA(1));

        $clusterId = $this->getClusterForConv($this->convA(1));
        self::assertNotNull($clusterId, 'the valid wallet anchor must still form a cluster');

        $anchorIds = $this->conn->fetchFirstColumn(
            'SELECT indicator_id FROM threat_actor_cluster_ioc WHERE cluster_id = :id',
            ['id' => $clusterId],
        );
        self::assertContains($walletId, $anchorIds, 'the valid anchor is persisted');
        self::assertNotContains($ibanId, $anchorIds, 'a false-positive IOC must not be persisted as cluster anchor metadata');
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
