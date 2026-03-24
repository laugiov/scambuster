<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cleanup:weekly',
    description: 'Soft-delete old closed conversations and purge stale LLM usage records',
)]
class WeeklyCleanupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('conv-days', null, InputOption::VALUE_REQUIRED, 'Soft-delete closed conversations older than N days', '90')
            ->addOption('llm-days', null, InputOption::VALUE_REQUIRED, 'Purge LLM usage records older than N days', '180')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        /** @var string $convDaysRaw */
        $convDaysRaw = $input->getOption('conv-days');
        /** @var string $llmDaysRaw */
        $llmDaysRaw = $input->getOption('llm-days');
        $convDays = (int) $convDaysRaw;
        $llmDays = (int) $llmDaysRaw;

        if ($dryRun) {
            $io->note('Dry run mode — no changes will be made.');
        }

        // 1. Soft-delete old closed conversations
        $convCutoff = (new \DateTimeImmutable("-{$convDays} days"))->format('Y-m-d H:i:s');
        $convCount = $this->softDeleteConversations($convCutoff, $dryRun);
        $io->text(sprintf('Conversations soft-deleted: %d (older than %d days)', $convCount, $convDays));

        // 2. Purge old LLM usage records
        $llmCutoff = (new \DateTimeImmutable("-{$llmDays} days"))->format('Y-m-d H:i:s');
        $llmCount = $this->purgeLlmUsage($llmCutoff, $dryRun);
        $io->text(sprintf('LLM usage records purged: %d (older than %d days)', $llmCount, $llmDays));

        $io->success(sprintf('Cleanup complete. Conversations: %d, LLM records: %d.', $convCount, $llmCount));
        $this->logger->info('[WeeklyCleanup] Cleanup complete', [
            'conversations_deleted' => $convCount,
            'llm_records_purged' => $llmCount,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    private function softDeleteConversations(string $cutoff, bool $dryRun): int
    {
        $countSql = "SELECT COUNT(*) FROM conversation
                     WHERE status = 'closed'
                       AND ts_last < :cutoff
                       AND deleted_at IS NULL";

        /** @var int|string|false $raw */
        $raw = $this->connection->fetchOne($countSql, ['cutoff' => $cutoff]);
        $count = (int) $raw;

        if (!$dryRun && $count > 0) {
            $this->connection->executeStatement(
                "UPDATE conversation SET deleted_at = NOW()
                 WHERE status = 'closed'
                   AND ts_last < :cutoff
                   AND deleted_at IS NULL",
                ['cutoff' => $cutoff]
            );
        }

        return $count;
    }

    private function purgeLlmUsage(string $cutoff, bool $dryRun): int
    {
        $countSql = 'SELECT COUNT(*) FROM llm_usage WHERE created_at < :cutoff';
        /** @var int|string|false $raw */
        $raw = $this->connection->fetchOne($countSql, ['cutoff' => $cutoff]);
        $count = (int) $raw;

        if (!$dryRun && $count > 0) {
            $this->connection->executeStatement(
                'DELETE FROM llm_usage WHERE created_at < :cutoff',
                ['cutoff' => $cutoff]
            );
        }

        return $count;
    }
}
