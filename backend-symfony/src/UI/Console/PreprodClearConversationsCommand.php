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
    description: 'Supprime toutes les conversations et messages de la base preprod'
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
            'Force la suppression sans confirmation'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Vérifier qu'on est bien en preprod
        $dbUrl = $_ENV['DATABASE_URL'] ?? '';

        /** @var string $dbUrl */
        if (!str_contains($dbUrl, 'preprod')) {
            $io->error('ATTENTION: Cette commande ne peut être exécutée que sur la base preprod!');
            $io->note('DATABASE_URL doit contenir "preprod"');

            return Command::FAILURE;
        }

        $counts = $this->clearService->countExistingData();
        $countConv = $counts['conversations'];
        $countMsg = $counts['messages'];

        if ($countConv === 0) {
            $io->success('Aucune conversation à supprimer');

            return Command::SUCCESS;
        }

        $io->section('État actuel de la base preprod');
        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Conversations', $countConv],
                ['Messages', $countMsg],
            ]
        );

        // Demander confirmation sauf si --force
        if (!$input->getOption('force') && !$io->confirm('Voulez-vous VRAIMENT supprimer toutes ces données ?', false)) {
            $io->warning('Opération annulée');

            return Command::SUCCESS;
        }

        $io->section('Suppression en cours');

        try {
            $this->clearService->clearAll();

            $io->success(sprintf(
                'Base preprod nettoyée: %d conversations et %d messages supprimés',
                $countConv,
                $countMsg
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Erreur lors de la suppression: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
