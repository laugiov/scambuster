<?php

declare(strict_types=1);

namespace App\Tests\Integration\Clustering;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies that the clustering migration created all required tables and indexes.
 * This test is written BEFORE the migration (TDD red → green).
 */
class MigrationTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = self::getContainer()->get(Connection::class);
    }

    public function testThreatActorClusterTableExists(): void
    {
        $exists = $this->tableExists('threat_actor_cluster');
        $this->assertTrue($exists, 'Table threat_actor_cluster should exist');
    }

    public function testThreatActorClusterConversationTableExists(): void
    {
        $exists = $this->tableExists('threat_actor_cluster_conversation');
        $this->assertTrue($exists, 'Table threat_actor_cluster_conversation should exist');
    }

    public function testThreatActorClusterIocTableExists(): void
    {
        $exists = $this->tableExists('threat_actor_cluster_ioc');
        $this->assertTrue($exists, 'Table threat_actor_cluster_ioc should exist');
    }

    public function testObservedIocIndicatorIdIndexExists(): void
    {
        $exists = $this->indexExists('observed_ioc', 'idx_observed_ioc_indicator_id');
        $this->assertTrue($exists, 'Index idx_observed_ioc_indicator_id should exist on observed_ioc');
    }

    public function testClusterTableHasRequiredColumns(): void
    {
        $columns = $this->getColumnNames('threat_actor_cluster');

        $required = [
            'cluster_id', 'stix_id', 'name', 'status',
            'conversation_count', 'anchor_ioc_count',
            'sophistication', 'primary_scam_types', 'goals',
            'first_seen', 'last_seen', 'merged_into_id',
            'algorithm_version', 'last_clustered_at',
            'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertContains($col, $columns, "Column '{$col}' should exist in threat_actor_cluster");
        }
    }

    public function testClusterConversationTableHasRequiredColumns(): void
    {
        $columns = $this->getColumnNames('threat_actor_cluster_conversation');

        $this->assertContains('cluster_id', $columns);
        $this->assertContains('conv_id', $columns);
        $this->assertContains('linked_at', $columns);
    }

    public function testClusterIocTableHasRequiredColumns(): void
    {
        $columns = $this->getColumnNames('threat_actor_cluster_ioc');

        $this->assertContains('cluster_id', $columns);
        $this->assertContains('indicator_id', $columns);
        $this->assertContains('ioc_type', $columns);
        $this->assertContains('value_norm_hash', $columns);
        $this->assertContains('conv_count', $columns);
    }

    public function testConvIdUniqueInClusterConversation(): void
    {
        $exists = $this->indexExists('threat_actor_cluster_conversation', 'idx_tacc_conv_id');
        $this->assertTrue($exists, 'Unique index idx_tacc_conv_id should exist');
    }

    private function tableExists(string $table): bool
    {
        $result = $this->conn->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = :table)",
            ['table' => $table]
        );

        return (bool) $result;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = $this->conn->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM pg_indexes WHERE tablename = :table AND indexname = :index)",
            ['table' => $table, 'index' => $indexName]
        );

        return (bool) $result;
    }

    /**
     * @return list<string>
     */
    private function getColumnNames(string $table): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT column_name FROM information_schema.columns WHERE table_name = :table",
            ['table' => $table]
        );

        return array_map(fn (array $row) => (string) $row['column_name'], $rows);
    }
}
