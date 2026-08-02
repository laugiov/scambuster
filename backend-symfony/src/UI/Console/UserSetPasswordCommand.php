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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Rotate an existing user's password.
 *
 * Usage:
 *   bin/console app:user:set-password user@example.com --generate
 *   bin/console app:user:set-password user@example.com --password='...'
 */
#[AsCommand(
    name: 'app:user:set-password',
    description: "Set (rotate) an existing user's password",
)]
final class UserSetPasswordCommand extends Command
{
    use ResolvesPasswordInput;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepositoryInterface $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of the user to update')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'New password (min 12 chars). Omit to prompt or use --generate.')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a strong random password and print it once');
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

        [$password, $error] = $this->resolvePassword($input, $io, $output);

        if ($error !== null) {
            $io->error($error);

            return Command::INVALID;
        }

        $user->setPassword($this->hasher->hashPassword($user, (string) $password));
        $this->em->flush();

        $this->auditLogger->log(
            eventType: AuditEventType::USER_PASSWORD_RESET,
            actorId: 'cli',
            action: 'user.set_password',
            outcome: 'success',
            resourceType: 'user',
            resourceId: $email,
            actorType: 'system',
        );

        $io->success(sprintf('Password updated for %s.', $email));

        return Command::SUCCESS;
    }
}
