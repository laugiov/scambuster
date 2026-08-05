<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Meta\PreprodCopyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'preprod:copy-conversations',
    description: 'Copy conversations from preprod to dev for API testing'
)]
class PreprodCopyConversationsCommand extends Command
{
    public function __construct(
        private readonly PreprodCopyService $copyService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Copy Conversations Preprod → Dev');

        // 1. Verify preprod connection
        $io->section('1. Connecting to preprod');

        try {
            $preprodCount = $this->copyService->countPreprodConversations();
            $io->success(sprintf('Connected to preprod: %d conversations found', $preprodCount));
        } catch (\Exception $e) {
            $io->error('Unable to connect to preprod: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // 2. Clean dev
        $io->section('2. Cleaning the dev database');

        try {
            $this->copyService->clearDevData();
            $io->success('Dev database cleaned');
        } catch (\Exception $e) {
            $io->error('Cleanup error: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // 3. Copy conversations
        $io->section('3. Copying conversations');

        try {
            $convCopied = $this->copyService->copyConversations();
            $io->success(sprintf('%d conversations copied', $convCopied));
        } catch (\Exception $e) {
            $io->error('Copy error: ' . $e->getMessage());
            $io->note('Ensure the dblink extension is installed: CREATE EXTENSION IF NOT EXISTS dblink;');

            return Command::FAILURE;
        }

        // 4. Copy messages
        $io->section('4. Copying messages');

        try {
            $msgCopied = $this->copyService->copyMessages();
            $io->success(sprintf('%d messages copied', $msgCopied));
        } catch (\Exception $e) {
            $io->warning('Message copy error: ' . $e->getMessage());
        }

        // 5. Final statistics
        $io->section('5. Final statistics');

        $stats = $this->copyService->getDevStats();

        $io->table(
            ['Metric', 'Value'],
            [
                ['Conversations in dev', $stats['conversations']],
                ['Messages in dev', $stats['messages']],
            ]
        );

        $io->success('Copy completed successfully!');
        $io->note('Preprod conversations are now accessible through the dev API');

        return Command::SUCCESS;
    }
}
