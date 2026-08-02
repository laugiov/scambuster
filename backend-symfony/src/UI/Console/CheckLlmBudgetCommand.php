<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Monitoring\BudgetThresholdNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Periodic budget threshold check.
 *
 * Triggered by the scheduler (every 15 minutes per
 * `infra/docker/backend/scheduler.sh`). Delegates entirely to
 * `BudgetThresholdNotifier::check()` which handles the threshold
 * detection and the daily deduplication.
 *
 * Idempotent: safe to run as often as the operator wishes.
 */
#[AsCommand(
    name: 'app:llm:check-budget',
    description: 'Check the LLM monthly budget threshold and emit an audit warning event if exceeded',
)]
final class CheckLlmBudgetCommand extends Command
{
    public function __construct(
        private readonly BudgetThresholdNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[065b] Checking LLM monthly budget threshold...</info>');
        $this->notifier->check();
        $output->writeln('<info>[065b] Budget check complete.</info>');

        return Command::SUCCESS;
    }
}
