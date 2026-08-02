<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console;

use App\Application\Stix\ThreatActorStixBuilder;
use App\UI\Console\ComputeClusterSophisticationCommand;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \App\UI\Console\ComputeClusterSophisticationCommand
 */
final class ComputeClusterSophisticationCommandTest extends TestCase
{
    /**
     * Test: cluster with >24h avg engagement -> sophistication >= 'intermediate'.
     */
    public function testHighEngagementClusterIsAtLeastIntermediate(): void
    {
        $builder = new ThreatActorStixBuilder();

        // >24h engagement = +2, avg_turns=8 (+1), 3 ioc types (+1), no injection = score 4 -> intermediate
        $sophistication = $builder->inferSophistication([
            'avg_engagement_hours' => 48.0,
            'unique_ioc_type_count' => 3,
            'avg_turns' => 8.0,
            'has_injection_attempts' => false,
        ]);

        $levels = ['none' => 0, 'minimal' => 1, 'intermediate' => 2, 'advanced' => 3];
        self::assertGreaterThanOrEqual($levels['intermediate'], $levels[$sophistication]);
    }

    /**
     * Test: command updates cluster when sophistication changes.
     */
    public function testCommandUpdatesCluster(): void
    {
        $conn = $this->createMock(Connection::class);
        $builder = new ThreatActorStixBuilder();

        $callIndex = 0;
        $conn->method('fetchAllAssociative')
            ->willReturnCallback(function () use (&$callIndex): array {
                ++$callIndex;

                // First call: cluster list
                return [
                    [
                        'cluster_id' => 'cluster-1',
                        'name' => 'Test Cluster',
                        'sophistication' => 'none',
                    ],
                ];
            });

        // Return high engagement metrics
        $conn->method('fetchAssociative')
            ->willReturn([
                'avg_engagement_hours' => 48.0,
                'avg_turns' => 20.0,
            ]);

        $fetchOneCallIndex = 0;
        $conn->method('fetchOne')
            ->willReturnCallback(function () use (&$fetchOneCallIndex): int {
                ++$fetchOneCallIndex;

                if ($fetchOneCallIndex === 1) {
                    return 5; // unique_ioc_type_count
                }

                return 0; // injection count
            });

        $conn->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('UPDATE threat_actor_cluster'),
                self::callback(function (array $params): bool {
                    // Should update from 'none' to at least 'intermediate'
                    return $params['id'] === 'cluster-1'
                        && \in_array($params['soph'], ['intermediate', 'advanced'], true);
                })
            );

        $tester = $this->createTester($conn, $builder);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Updated', $tester->getDisplay());
    }

    /**
     * Test: --dry-run does not write.
     */
    public function testDryRunDoesNotUpdate(): void
    {
        $conn = $this->createMock(Connection::class);
        $builder = new ThreatActorStixBuilder();

        $conn->method('fetchAllAssociative')
            ->willReturn([
                [
                    'cluster_id' => 'cluster-2',
                    'name' => 'Test Cluster 2',
                    'sophistication' => 'none',
                ],
            ]);

        $conn->method('fetchAssociative')
            ->willReturn([
                'avg_engagement_hours' => 48.0,
                'avg_turns' => 20.0,
            ]);

        $conn->method('fetchOne')
            ->willReturn(5);

        $conn->expects(self::never())
            ->method('executeStatement');

        $tester = $this->createTester($conn, $builder);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }

    /**
     * Test: no clusters -> success with message.
     */
    public function testNoClustersReturnsSuccess(): void
    {
        $conn = $this->createMock(Connection::class);
        $builder = new ThreatActorStixBuilder();

        $conn->method('fetchAllAssociative')
            ->willReturn([]);

        $tester = $this->createTester($conn, $builder);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No active clusters', $tester->getDisplay());
    }

    /**
     * Test inferSophistication directly — low engagement cluster stays 'none'.
     */
    public function testLowEngagementClusterIsNone(): void
    {
        $builder = new ThreatActorStixBuilder();

        $sophistication = $builder->inferSophistication([
            'avg_engagement_hours' => 0.5,
            'unique_ioc_type_count' => 1,
            'avg_turns' => 2.0,
            'has_injection_attempts' => false,
        ]);

        self::assertSame('none', $sophistication);
    }

    private function createTester(Connection $conn, ThreatActorStixBuilder $builder): CommandTester
    {
        $command = new ComputeClusterSophisticationCommand($conn, $builder);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:compute:cluster-sophistication'));
    }
}
