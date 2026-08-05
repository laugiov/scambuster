<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\ThreatActor\ThreatActorPsychProfileGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Batch-generate the per-cluster threat-actor psychological profile.
 *
 * Iterates every non-merged cluster, calls the LLM via
 * ThreatActorPsychProfileGenerator, and upserts the result. Idempotent by
 * default (skips clusters that already have a profile); --force re-generates.
 * Scheduled daily via infra/docker/backend/scheduler.sh after clustering.
 */
#[AsCommand(
    name: 'app:actor:compute-psych-profiles',
    description: 'Generate the per-cluster threat-actor psychological profile (one LLM call per cluster)',
)]
final class ComputeActorPsychProfilesCommand extends Command
{
    public function __construct(
        private readonly ThreatActorPsychProfileGenerator $generator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('cluster', null, InputOption::VALUE_REQUIRED, 'Limit to this cluster_id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-generate clusters that already have a profile')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Cumulative cost cap (USD)', '2.00')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List clusters that would be processed, do not call the LLM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $clusterOpt = $input->getOption('cluster');
        $clusterFilter = \is_string($clusterOpt) ? $clusterOpt : '';
        $force = (bool) $input->getOption('force');
        $budgetOpt = $input->getOption('budget-usd');
        $budgetUsd = is_numeric($budgetOpt) ? (float) $budgetOpt : 2.00;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Threat-actor psychological profile batch');

        $clusters = $this->loadClusters($clusterFilter);

        if ($clusters === []) {
            $io->warning('No clusters matched the filters.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  %d candidate cluster(s) | force: %s | budget: $%.2f | dry-run: %s', \count($clusters), $force ? 'yes' : 'no', $budgetUsd, $dryRun ? 'yes' : 'no'));
        $io->newLine();

        // ~$0.002 per cluster on gpt-4o-mini (larger prompt than the persona mirror).
        $costPerCluster = 0.002;
        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $cumulative = 0.0;

        foreach ($clusters as $i => $clusterId) {
            if ($cumulative >= $budgetUsd) {
                $io->warning(sprintf('Budget cap reached ($%.4f / $%.2f). Stopping.', $cumulative, $budgetUsd));

                break;
            }

            if (!$force && $this->generator->exists($clusterId)) {
                ++$skipCount;

                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  [%d/%d] would generate %s', $i + 1, \count($clusters), $clusterId));
                ++$okCount;

                continue;
            }

            $profile = $this->generator->generateForCluster($clusterId);
            $cumulative += $costPerCluster;

            if ($profile !== null) {
                $io->writeln(sprintf('  [%d/%d] %s — OK (%s, cumulative $%.4f)', $i + 1, \count($clusters), $clusterId, $profile->dominantLever->value, $cumulative));
                ++$okCount;
            } else {
                $io->writeln(sprintf('  [%d/%d] %s — skipped (no inbound corpus or LLM failure)', $i + 1, \count($clusters), $clusterId));
                ++$errCount;
            }
        }

        $io->newLine();
        $io->writeln(sprintf('ok=%d skipped=%d errors=%d est_cost=$%.4f', $okCount, $skipCount, $errCount, $cumulative));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function loadClusters(string $clusterFilter): array
    {
        $sql = 'SELECT cluster_id FROM threat_actor_cluster WHERE merged_into_id IS NULL';
        $params = [];

        if ($clusterFilter !== '') {
            $sql .= ' AND cluster_id = :cid';
            $params['cid'] = $clusterFilter;
        }

        $sql .= ' ORDER BY last_seen DESC NULLS LAST';

        $rows = $this->connection->fetchFirstColumn($sql, $params);
        $out = [];

        foreach ($rows as $row) {
            if (\is_string($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
