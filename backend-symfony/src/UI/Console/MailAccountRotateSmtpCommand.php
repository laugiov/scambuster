<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\MailAccountManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rotate the SMTP DSN for an existing mail account.
 *
 * Usage:
 *   bin/console app:mail-account:rotate-smtp <account_id> --smtp-dsn='smtps://new:pass@smtp.example.com:465'
 */
#[AsCommand(
    name: 'app:mail-account:rotate-smtp',
    description: 'Replace the encrypted SMTP DSN for an existing mail account',
)]
final class MailAccountRotateSmtpCommand extends Command
{
    public function __construct(
        private readonly MailAccountManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account-id', InputArgument::REQUIRED, 'UUID of the mail account')
            ->addOption('smtp-dsn', null, InputOption::VALUE_REQUIRED, 'New SMTP DSN to encrypt and store');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $accountId */
        $accountId = $input->getArgument('account-id');
        $newDsn = $input->getOption('smtp-dsn');

        if (!\is_string($newDsn) || $newDsn === '') {
            $io->error('--smtp-dsn is required');

            return Command::INVALID;
        }

        try {
            $this->manager->rotateSmtp($accountId, $newDsn);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('SMTP DSN rotated for account %s.', $accountId));

        return Command::SUCCESS;
    }
}
