<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Stix\ThreatActorStixBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 075f — Compute sophistication level for all clusters from conversation metrics.
 *
 * Aggregates engagement hours, IOC type diversity, turn counts, and injection
 * attempts for each cluster, then calls the same scoring logic as
 * ThreatActorStixBuilder::inferSophistication() to update the cluster record.
 */
#[AsCommand(
    name: 'app:compute:cluster-sophistication',
    description: 'Compute sophistication level for all clusters from conversation metrics',
)]
final class ComputeClusterSophisticationCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly ThreatActorStixBuilder $stixBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Spec 075f — Compute cluster sophistication');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        // Fetch all active/suspect clusters
        $clusters = $this->conn->fetchAllAssociative(
            "SELECT cluster_id, name, sophistication
             FROM threat_actor_cluster
             WHERE status IN ('active', 'suspect')
             ORDER BY cluster_id"
        );

        if ($clusters === []) {
            $io->success('No active clusters found.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Processing %d clusters.', \count($clusters)));

        $updated = 0;
        $unchanged = 0;

        foreach ($clusters as $cluster) {
            $clusterId = \is_string($cluster['cluster_id'] ?? null) ? $cluster['cluster_id'] : '';
            $oldSoph = \is_string($cluster['sophistication'] ?? null) ? $cluster['sophistication'] : 'none';
            $clusterName = \is_string($cluster['name'] ?? null) ? $cluster['name'] : '';

            $metrics = $this->aggregateClusterMetrics($clusterId);
            $newSoph = $this->stixBuilder->inferSophistication($metrics);

            if ($newSoph !== $oldSoph) {
                if (!$dryRun) {
                    $this->conn->executeStatement(
                        'UPDATE threat_actor_cluster SET sophistication = :soph, updated_at = :now WHERE cluster_id = :id',
                        [
                            'soph' => $newSoph,
                            'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                            'id' => $clusterId,
                        ]
                    );
                }

                $io->text(\sprintf(
                    '  %s (%s): %s -> %s',
                    substr($clusterId, 0, 8),
                    $clusterName,
                    $oldSoph,
                    $newSoph
                ));
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Updated', $updated],
                ['Unchanged', $unchanged],
            ]
        );

        if ($dryRun) {
            $io->success(\sprintf('Dry-run complete: %d would be updated, %d unchanged.', $updated, $unchanged));
        } else {
            $io->success(\sprintf('Done: %d updated, %d unchanged.', $updated, $unchanged));
        }

        return Command::SUCCESS;
    }

    /**
     * Aggregate metrics from cluster conversations for sophistication inference.
     *
     * @return array{avg_engagement_hours: float, unique_ioc_type_count: int, avg_turns: float, has_injection_attempts: bool}
     */
    private function aggregateClusterMetrics(string $clusterId): array
    {
        // Average engagement hours and turns from conversations
        $convMetrics = $this->conn->fetchAssociative(
            'SELECT
                AVG(EXTRACT(EPOCH FROM (c.ts_last - c.ts_first)) / 3600.0) AS avg_engagement_hours,
                AVG(c.turns_count) AS avg_turns
             FROM threat_actor_cluster_conversation tacc
             JOIN conversation c ON c.conv_id = tacc.conv_id
             WHERE tacc.cluster_id = :clusterId',
            ['clusterId' => $clusterId]
        );

        $avgHours = \is_numeric($convMetrics['avg_engagement_hours'] ?? null) ? (float) $convMetrics['avg_engagement_hours'] : 0.0;
        $avgTurns = \is_numeric($convMetrics['avg_turns'] ?? null) ? (float) $convMetrics['avg_turns'] : 0.0;

        // Unique IOC type count across all conversations in the cluster
        /** @var int|string|false $iocTypeCount */
        $iocTypeCount = $this->conn->fetchOne(
            'SELECT COUNT(DISTINCT i.type)
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
             WHERE tacc.cluster_id = :clusterId',
            ['clusterId' => $clusterId]
        );

        // Check for injection attempts across cluster conversations
        /** @var int|string|false $injectionCount */
        $injectionCount = $this->conn->fetchOne(
            "SELECT COUNT(*)
             FROM message m
             JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
             WHERE tacc.cluster_id = :clusterId
               AND m.headers::text LIKE '%injection_detected%'",
            ['clusterId' => $clusterId]
        );

        return [
            'avg_engagement_hours' => $avgHours,
            'unique_ioc_type_count' => (int) $iocTypeCount,
            'avg_turns' => $avgTurns,
            'has_injection_attempts' => ((int) $injectionCount) > 0,
        ];
    }
}
