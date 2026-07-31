<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\AuditLoggerInterface;
use App\Domain\Audit\AuditEventType;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Create a user from the command line.
 *
 * Usage:
 *   bin/console app:user:create --email=admin@example.com --admin --generate
 *   bin/console app:user:create --email=analyst@example.com --password='...'
 *
 * The password is taken from --password, or generated with --generate (printed
 * once), or prompted for interactively. ROLE_ADMIN grants all permissions.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Create a user (with a generated, provided, or prompted password)',
)]
final class UserCreateCommand extends Command
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
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address (login identifier)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password (min 12 chars). Omit to prompt or use --generate.')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a strong random password and print it once')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN (all permissions)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $emailInput = $input->getOption('email');

        if (!\is_string($emailInput) || trim($emailInput) === '') {
            $io->error('--email is required');

            return Command::INVALID;
        }

        // Normalize so login (exact match) always resolves the account, and to
        // prevent case/whitespace-variant duplicate accounts (e.g. Admin@x vs admin@x).
        $email = strtolower(trim($emailInput));

        if (\strlen($email) > 255 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            $io->error('Invalid email address.');

            return Command::INVALID;
        }

        if ($this->users->findByEmail($email) !== null) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        [$password, $error] = $this->resolvePassword($input, $io, $output);

        if ($error !== null) {
            $io->error($error);

            return Command::INVALID;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->hasher->hashPassword($user, (string) $password));
        $user->setRoles((bool) $input->getOption('admin') ? ['ROLE_ADMIN'] : ['ROLE_USER']);

        try {
            $this->em->persist($user);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost a race between the existence check and the insert.
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $this->auditLogger->log(
            eventType: AuditEventType::USER_CREATED,
            actorId: 'cli',
            action: 'user.create',
            outcome: 'success',
            resourceType: 'user',
            resourceId: $email,
            details: ['roles' => $user->getRoles()],
            actorType: 'system',
        );

        $io->success(sprintf('User created: %s (%s)', $email, implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
