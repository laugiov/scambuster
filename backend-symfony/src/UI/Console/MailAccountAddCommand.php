<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\MailAccountManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 050 — Add a new mail account with optional per-account SMTP credentials.
 *
 * Usage:
 *   bin/console app:mail-account:add \
 *       --owner-id=<uuid> \
 *       --email=user@example.com \
 *       --smtp-dsn='smtps://user:pass@smtp.example.com:465' \
 *       [--label="Production mailbox"] \
 *       [--endpoint=imap.example.com]
 *
 * Outputs the new account_id (UUID) on stdout for use in n8n workflows.
 */
#[AsCommand(
    name: 'app:mail-account:add',
    description: 'Spec 050 — add a mail account with optional per-account SMTP DSN (encrypted at rest)',
)]
final class MailAccountAddCommand extends Command
{
    public function __construct(
        private readonly MailAccountManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('owner-id', null, InputOption::VALUE_REQUIRED, 'Owner UUID (existing user/system identity)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Reply-from email address')
            ->addOption('smtp-dsn', null, InputOption::VALUE_OPTIONAL, 'Per-account SMTP DSN (encrypted before storage). Omit to use global MAILER_DSN.')
            ->addOption('label', null, InputOption::VALUE_OPTIONAL, 'Operator-friendly internal name')
            ->addOption('endpoint', null, InputOption::VALUE_OPTIONAL, 'IMAP host fingerprint', 'imap.example.com');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $ownerId = $input->getOption('owner-id');
        $email = $input->getOption('email');
        $smtpDsn = $input->getOption('smtp-dsn');
        $label = $input->getOption('label');
        $endpoint = $input->getOption('endpoint');

        if (!\is_string($ownerId) || $ownerId === '') {
            $io->error('--owner-id is required');

            return Command::INVALID;
        }

        if (!\is_string($email) || $email === '') {
            $io->error('--email is required');

            return Command::INVALID;
        }

        try {
            $accountId = $this->manager->addAccount(
                ownerId: $ownerId,
                email: $email,
                smtpDsn: \is_string($smtpDsn) && $smtpDsn !== '' ? $smtpDsn : null,
                label: \is_string($label) ? $label : null,
                endpoint: \is_string($endpoint) ? $endpoint : 'imap.example.com',
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Mail account created: %s', $accountId));
        $output->writeln($accountId);

        return Command::SUCCESS;
    }
}
