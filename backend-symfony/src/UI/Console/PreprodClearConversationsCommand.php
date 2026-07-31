<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Meta\PreprodClearService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'preprod:clear-conversations',
    description: 'Delete all conversations and messages from the preprod database'
)]
class PreprodClearConversationsCommand extends Command
{
    public function __construct(
        private readonly PreprodClearService $clearService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Force deletion without confirmation'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Verify we are in preprod
        $dbUrl = $_ENV['DATABASE_URL'] ?? '';

        /** @var string $dbUrl */
        if (!str_contains($dbUrl, 'preprod')) {
            $io->error('WARNING: This command can only be run on the preprod database!');
            $io->note('DATABASE_URL must contain "preprod"');

            return Command::FAILURE;
        }

        $counts = $this->clearService->countExistingData();
        $countConv = $counts['conversations'];
        $countMsg = $counts['messages'];

        if ($countConv === 0) {
            $io->success('No conversations to delete');

            return Command::SUCCESS;
        }

        $io->section('Current state of the preprod database');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Conversations', $countConv],
                ['Messages', $countMsg],
            ]
        );

        // Ask for confirmation unless --force
        if (!$input->getOption('force') && !$io->confirm('Do you REALLY want to delete all this data?', false)) {
            $io->warning('Operation cancelled');

            return Command::SUCCESS;
        }

        $io->section('Deletion in progress');

        try {
            $this->clearService->clearAll();

            $io->success(sprintf(
                'Preprod database cleaned: %d conversations and %d messages deleted',
                $countConv,
                $countMsg
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Deletion error: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
