<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Scambaiting\ConversationClosureService;
use App\Domain\Communication\Conversation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'preprod:calculate-rewards',
    description: 'Calcule les rewards pour toutes les conversations CLOSED sans reward'
)]
class CalculateRewardsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationClosureService $closureService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Recalculer même si reward déjà présent');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = $input->getOption('force');

        $io->title('🧮 Calcul des rewards pour conversations preprod');

        // Récupérer toutes les conversations CLOSED
        $qb = $this->em->getRepository(Conversation::class)->createQueryBuilder('c');
        $qb->where("c.status = 'closed' OR c.status = 'CLOSED'");

        if (!$force) {
            $qb->andWhere('c.rewardValue IS NULL');
        }

        /** @var Conversation[] $conversations */
        $conversations = $qb->getQuery()->getResult();

        if (empty($conversations)) {
            $io->success('Aucune conversation à traiter');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Trouvé %d conversations à traiter', count($conversations)));

        $progressBar = $io->createProgressBar(count($conversations));
        $progressBar->start();

        $success = 0;
        $errors = 0;

        foreach ($conversations as $conversation) {
            try {
                $this->closureService->recalculateMetricsAndReward($conversation->getConvId());
                $success++;
            } catch (\Exception $e) {
                $errors++;
                $io->error(sprintf('Erreur pour %s: %s', $conversation->getConvId(), $e->getMessage()));
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->table(
            ['Métrique', 'Valeur'],
            [
                ['Succès', $success],
                ['Erreurs', $errors],
                ['Total', count($conversations)],
            ]
        );

        return Command::SUCCESS;
    }
}
