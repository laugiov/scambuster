<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List all users with their roles and permission count.
 *
 * Usage: bin/console app:user:list
 */
#[AsCommand(
    name: 'app:user:list',
    description: 'List users (email, roles, permission count)',
)]
final class UserListCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy([], ['email' => 'ASC']);

        if ($users === []) {
            $io->info('No users.');

            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn (User $u): array => [
                $u->getEmail(),
                implode(', ', $u->getRoles()),
                (string) \count($u->getPermissions()),
            ],
            $users,
        );

        $io->table(['Email', 'Roles', 'Permissions'], $rows);

        return Command::SUCCESS;
    }
}
