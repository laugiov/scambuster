<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Auth\LoginHashGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'login-hash:generate',
    description: 'Generate a login_hash for a given login'
)]
class GenerateLoginHashCommand extends Command
{
    public function __construct(private readonly LoginHashGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('login', InputArgument::REQUIRED, 'The login (email or username)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $login = $input->getArgument('login');
        $hash = $this->generator->generate($login);
        $output->writeln($hash);

        return Command::SUCCESS;
    }
}
