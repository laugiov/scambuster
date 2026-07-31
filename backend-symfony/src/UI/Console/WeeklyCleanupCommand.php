<?php

declare(strict_types=1);

namespace App\UI\Console;

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
    description: 'Soft-delete old closed conversations and purge stale LLM usage + prompt-canary-job records',
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
            ->addOption('canary-days', null, InputOption::VALUE_REQUIRED, 'Purge terminal (succeeded/failed) prompt-canary jobs older than N days', '30')
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
        /** @var string $canaryDaysRaw */
        $canaryDaysRaw = $input->getOption('canary-days');
        $convDays = (int) $convDaysRaw;
        $llmDays = (int) $llmDaysRaw;
        $canaryDays = (int) $canaryDaysRaw;

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

        // 3. Purge old terminal prompt-canary jobs (candidate_body + verdict JSON per row grow
        //    the table unbounded; a verdict older than the window is no longer actionable).
        $canaryCutoff = (new \DateTimeImmutable("-{$canaryDays} days"))->format('Y-m-d H:i:s');
        $canaryCount = $this->purgeCanaryJobs($canaryCutoff, $dryRun);
        $io->text(sprintf('Prompt-canary jobs purged: %d (terminal, older than %d days)', $canaryCount, $canaryDays));

        $io->success(sprintf('Cleanup complete. Conversations: %d, LLM records: %d, canary jobs: %d.', $convCount, $llmCount, $canaryCount));
        $this->logger->info('[WeeklyCleanup] Cleanup complete', [
            'conversations_deleted' => $convCount,
            'llm_records_purged' => $llmCount,
            'canary_jobs_purged' => $canaryCount,
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

    /**
     * Only TERMINAL jobs are purged — 'succeeded'/'failed' (CanaryJobStatus). A 'pending' or
     * 'running' job is never touched, so an in-flight or queued validation is never lost.
     */
    private function purgeCanaryJobs(string $cutoff, bool $dryRun): int
    {
        $where = "status IN ('succeeded', 'failed') AND created_at < :cutoff";

        /** @var int|string|false $raw */
        $raw = $this->connection->fetchOne("SELECT COUNT(*) FROM prompt_canary_job WHERE {$where}", ['cutoff' => $cutoff]);
        $count = (int) $raw;

        if (!$dryRun && $count > 0) {
            $this->connection->executeStatement("DELETE FROM prompt_canary_job WHERE {$where}", ['cutoff' => $cutoff]);
        }

        return $count;
    }
}
