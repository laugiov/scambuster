<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\MailAccountManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Soft-disable a mail account by setting is_active = false.
 *
 * Usage:
 *   bin/console app:mail-account:disable <account_id>
 */
#[AsCommand(
    name: 'app:mail-account:disable',
    description: 'Soft-disable a mail account (sets is_active = false)',
)]
final class MailAccountDisableCommand extends Command
{
    public function __construct(
        private readonly MailAccountManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('account-id', InputArgument::REQUIRED, 'UUID of the mail account to disable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $accountId */
        $accountId = $input->getArgument('account-id');

        try {
            $this->manager->disableAccount($accountId);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Mail account %s disabled.', $accountId));

        return Command::SUCCESS;
    }
}
