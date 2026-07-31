<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\AuditLoggerInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\User\Repository\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant or revoke ROLE_ADMIN for an existing user. Idempotent.
 *
 * Usage:
 *   bin/console app:user:promote user@example.com
 *   bin/console app:user:promote user@example.com --demote
 */
#[AsCommand(
    name: 'app:user:promote',
    description: 'Grant ROLE_ADMIN to a user (or revoke it with --demote)',
)]
final class UserPromoteCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user to promote/demote')
            ->addOption('demote', null, InputOption::VALUE_NONE, 'Revoke ROLE_ADMIN instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');

        if (!\is_string($email) || trim($email) === '') {
            $io->error('email argument is required');

            return Command::INVALID;
        }

        // Same normalization as user creation so the account resolves regardless
        // of the case/whitespace the operator typed.
        $email = strtolower(trim($email));
        $user = $this->users->findByEmail($email);

        if ($user === null) {
            $io->error(sprintf('No user found with email "%s".', $email));

            return Command::FAILURE;
        }

        $demote = (bool) $input->getOption('demote');
        $roles = $user->getRoles();

        if ($demote) {
            $roles = array_diff($roles, ['ROLE_ADMIN']);
        } else {
            $roles[] = 'ROLE_ADMIN';
        }
        // Always keep a baseline role; de-duplicate.
        $roles[] = 'ROLE_USER';
        $user->setRoles(array_values(array_unique($roles)));
        $this->em->flush();

        $this->auditLogger->log(
            eventType: AuditEventType::USER_ROLE_CHANGED,
            actorId: 'cli',
            action: $demote ? 'user.demote' : 'user.promote',
            outcome: 'success',
            resourceType: 'user',
            resourceId: $email,
            details: ['roles' => $user->getRoles()],
            actorType: 'system',
        );

        $io->success(sprintf(
            '%s is now %s.',
            $email,
            $demote ? 'a regular user' : 'an administrator',
        ));

        return Command::SUCCESS;
    }
}
