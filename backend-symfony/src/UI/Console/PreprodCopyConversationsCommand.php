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
    description: 'Copie les conversations de preprod vers dev pour tests API'
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

        $io->title('Copie Conversations Preprod → Dev');

        // 1. Verify preprod connection
        $io->section('1. Connecting to preprod');

        try {
            $preprodCount = $this->copyService->countPreprodConversations();
            $io->success(sprintf('Connected to preprod: %d conversations found', $preprodCount));
        } catch (\Exception $e) {
            $io->error('Unable to connect to preprod: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // 2. Nettoyer dev
        $io->section('2. Nettoyage de la base dev');

        try {
            $this->copyService->clearDevData();
            $io->success('Dev database cleaned');
        } catch (\Exception $e) {
            $io->error('Erreur lors du nettoyage: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // 3. Copier conversations
        $io->section('3. Copie des conversations');

        try {
            $convCopied = $this->copyService->copyConversations();
            $io->success(sprintf('%d conversations copied', $convCopied));
        } catch (\Exception $e) {
            $io->error('Erreur lors de la copie: ' . $e->getMessage());
            $io->note('Ensure the dblink extension is installed: CREATE EXTENSION IF NOT EXISTS dblink;');

            return Command::FAILURE;
        }

        // 4. Copier messages
        $io->section('4. Copie des messages');

        try {
            $msgCopied = $this->copyService->copyMessages();
            $io->success(sprintf('%d messages copied', $msgCopied));
        } catch (\Exception $e) {
            $io->warning('Erreur lors de la copie des messages: ' . $e->getMessage());
        }

        // 5. Statistiques finales
        $io->section('5. Statistiques finales');

        $stats = $this->copyService->getDevStats();

        $io->table(
            ['Metric', 'Value'],
            [
                ['Conversations en dev', $stats['conversations']],
                ['Messages en dev', $stats['messages']],
            ]
        );

        $io->success('Copy completed successfully!');
        $io->note('Les conversations de preprod sont maintenant accessibles via l\'API dev');

        return Command::SUCCESS;
    }
}
