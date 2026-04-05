<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\IocContextService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ioc:compute-context',
    description: 'Compute structural context for IOCs without context (backfill + retry)',
)]
final class ComputeIocContextCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly IocContextService $contextService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max messages to process', '500')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recompute existing context')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only, no writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitRaw = $input->getOption('limit');
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 500;
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('IOC Context Computation');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        if ($force) {
            $io->note('Force mode: existing context will be recomputed.');
        }

        // Find messages with IOCs that don't have context yet
        $sql = 'SELECT oi.msg_id, COUNT(oi.obs_id) AS ioc_count'
            . ' FROM observed_ioc oi'
            . ' JOIN indicator i ON oi.indicator_id = i.indicator_id';

        if (!$force) {
            $sql .= ' LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id'
                . ' WHERE ic.id IS NULL';
        }

        $sql .= ' GROUP BY oi.msg_id'
            . ' ORDER BY MAX(oi.ts_observed) DESC'
            . ' LIMIT ' . $limit;

        $messages = $this->connection->fetchAllAssociative($sql);

        if (empty($messages)) {
            $io->success('No IOCs to process.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Found %d messages with IOCs to process.', \count($messages)));

        $processedMessages = 0;
        $processedIocs = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($messages as $msg) {
            $msgId = \is_string($msg['msg_id'] ?? null) ? $msg['msg_id'] : '';

            if ($msgId === '') {
                continue;
            }

            // Get IOCs for this message
            $iocRows = $this->connection->fetchAllAssociative(
                'SELECT oi.obs_id, oi.indicator_id, i.type AS ioc_type'
                . ' FROM observed_ioc oi'
                . ' JOIN indicator i ON oi.indicator_id = i.indicator_id'
                . ' WHERE oi.msg_id = :msgId',
                ['msgId' => $msgId]
            );

            if (empty($iocRows)) {
                continue;
            }

            $obsIocData = [];

            foreach ($iocRows as $row) {
                $obsIocData[] = [
                    'obs_id' => \is_string($row['obs_id'] ?? null) ? $row['obs_id'] : '',
                    'indicator_id' => \is_string($row['indicator_id'] ?? null) ? $row['indicator_id'] : '',
                    'ioc_type' => \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '',
                ];
            }

            if ($dryRun) {
                $io->text(\sprintf(
                    '  [dry-run] Would process message %s (%d IOCs)',
                    substr($msgId, 0, 8),
                    \count($obsIocData)
                ));
                $processedMessages++;
                $processedIocs += \count($obsIocData);

                continue;
            }

            if ($force) {
                // Delete existing context for these IOCs
                foreach ($obsIocData as $ioc) {
                    $this->connection->executeStatement(
                        'DELETE FROM ioc_context WHERE obs_id = :obsId',
                        ['obsId' => $ioc['obs_id']]
                    );
                }
            }

            try {
                $this->contextService->computeAndPersistForMessage($msgId, $obsIocData);
                $processedMessages++;
                $processedIocs += \count($obsIocData);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('[ComputeIocContext] Failed to process message', [
                    'msg_id' => $msgId,
                    'error' => $e->getMessage(),
                ]);
                $io->warning(\sprintf('Error processing message %s: %s', substr($msgId, 0, 8), $e->getMessage()));
            }
        }

        $io->success(\sprintf(
            'Processed %d messages, %d IOCs%s. Errors: %d.',
            $processedMessages,
            $processedIocs,
            $dryRun ? ' (dry-run)' : '',
            $errors
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
