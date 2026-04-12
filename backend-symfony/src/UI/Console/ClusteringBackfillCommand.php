<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Clustering\IocClusteringService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill clustering for all existing conversations.
 *
 * Iterates over all conversations and runs clusterConversation() on each.
 * Idempotent: re-running produces the same result (ON CONFLICT DO NOTHING).
 */
#[AsCommand(
    name: 'app:clustering:backfill',
    description: 'Backfill threat-actor clusters for all existing conversations',
)]
final class ClusteringBackfillCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly IocClusteringService $clusteringService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only, no writes')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max conversations to process', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limitRaw = $input->getOption('limit');
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 0;

        $io->title('Clustering Backfill');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        // Get all conversation IDs ordered by ts_first
        $sql = 'SELECT conv_id FROM conversation ORDER BY ts_first ASC';

        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        $convIds = $this->conn->fetchFirstColumn($sql);
        $total = \count($convIds);

        $io->info("Found {$total} conversations to process.");

        if ($dryRun) {
            // In dry-run, count how many have anchor IOCs
            $anchorTypes = $this->clusteringService->getAnchorTypes();

            if (empty($anchorTypes)) {
                $io->warning('No anchor IOC types configured.');

                return Command::SUCCESS;
            }

            $placeholders = implode(',', array_fill(0, \count($anchorTypes), '?'));
            /** @var int|string|false $withAnchors */
            $withAnchors = $this->conn->fetchOne(
                "SELECT COUNT(DISTINCT m.conv_id)
                 FROM message m
                 JOIN observed_ioc oi ON oi.msg_id = m.msg_id
                 JOIN indicator i ON i.indicator_id = oi.indicator_id
                 WHERE i.type IN ({$placeholders})",
                $anchorTypes
            );

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total conversations', (string) $total],
                    ['With anchor IOCs', (string) (int) $withAnchors],
                    ['Anchor types', implode(', ', $anchorTypes)],
                ]
            );

            return Command::SUCCESS;
        }

        // Process conversations
        $processed = 0;
        $clustered = 0;
        $errors = 0;

        $io->progressStart($total);

        foreach ($convIds as $convId) {
            /** @var string $cid */
            $cid = $convId;

            try {
                $this->clusteringService->clusterConversation($cid);
                $processed++;

                // Check if this conv ended up in a cluster
                $inCluster = $this->conn->fetchOne(
                    'SELECT 1 FROM threat_actor_cluster_conversation WHERE conv_id = :id',
                    ['id' => $cid]
                );

                if ($inCluster !== false) {
                    $clustered++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('[ClusteringBackfill] Error processing conversation', [
                    'conv_id' => $cid,
                    'error' => $e->getMessage(),
                ]);
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Summary
        /** @var int|string|false $rawClusterCount */
        $rawClusterCount = $this->conn->fetchOne(
            "SELECT COUNT(*) FROM threat_actor_cluster WHERE status != 'merged'"
        );

        $io->table(
            ['Metric', 'Value'],
            [
                ['Processed', (string) $processed],
                ['Clustered', (string) $clustered],
                ['Active clusters', (string) (int) $rawClusterCount],
                ['Errors', (string) $errors],
            ]
        );

        $io->success("Backfill complete: {$processed} conversations processed, {$clustered} clustered.");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
