<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\MailAccountManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 050 — List all mail accounts (without revealing SMTP credentials).
 */
#[AsCommand(
    name: 'app:mail-account:list',
    description: 'Spec 050 — list all mail accounts (NEVER reveals SMTP DSN)',
)]
final class MailAccountListCommand extends Command
{
    public function __construct(
        private readonly MailAccountManager $manager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rows = $this->manager->listAccounts();

        if ($rows === []) {
            $io->info('No mail accounts configured.');

            return Command::SUCCESS;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $tableRows[] = [
                $row['account_id'],
                $row['email'] ?? '(none)',
                $row['label'] ?? '',
                $row['has_custom_smtp'] ? 'yes' : 'no',
                $row['is_active'] ? 'active' : 'disabled',
            ];
        }

        $io->table(
            ['account_id', 'email', 'label', 'has_custom_smtp', 'status'],
            $tableRows,
        );

        return Command::SUCCESS;
    }
}
