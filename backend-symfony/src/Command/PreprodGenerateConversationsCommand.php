<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Communication\Channel;
use App\Domain\Communication\Persona;
use App\Domain\Communication\ScamType;
use App\Infrastructure\Preprod\ConversationGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'preprod:generate-conversations',
    description: 'Génère 10 000 conversations scam réalistes pour preprod'
)]
class PreprodGenerateConversationsCommand extends Command
{
    private const TOTAL_CONVERSATIONS = 10_000;
    private const BATCH_SIZE = 100;
    private const MIN_MESSAGES = 2;
    private const MAX_MESSAGES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationGenerator $generator,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Nombre de conversations à générer', self::TOTAL_CONVERSATIONS)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Taille des batches', self::BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation sans sauvegarde')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $countOption = $input->getOption('count');
        $batchSizeOption = $input->getOption('batch-size');

        $count = is_numeric($countOption) ? (int) $countOption : self::TOTAL_CONVERSATIONS;
        $batchSize = is_numeric($batchSizeOption) && (int) $batchSizeOption > 0 ? (int) $batchSizeOption : self::BATCH_SIZE;
        $dryRun = $input->getOption('dry-run');

        $io->title('🤖 Génération de conversations scam pour preprod');

        if ($dryRun) {
            $io->warning('Mode DRY-RUN: aucune donnée ne sera sauvegardée');
        }

        // Charger les entités de référence
        $io->section('📊 Chargement des données de référence');

        $personas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);
        $scamTypes = $this->em->getRepository(ScamType::class)->findBy(['active' => true]);
        $channels = $this->em->getRepository(Channel::class)->findAll();

        if (empty($personas) || empty($scamTypes) || empty($channels)) {
            $io->error('Données de référence manquantes. Assurez-vous que personas, scam_types et channels sont chargés.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Chargé: %d personas, %d scam types, %d channels',
            count($personas),
            count($scamTypes),
            count($channels)
        ));

        // Calculer la distribution
        $distribution = $this->calculateDistribution($count, count($personas), count($scamTypes));

        $io->section('📈 Distribution des conversations');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total conversations', number_format($count)],
                ['Personas', count($personas)],
                ['Scam types', count($scamTypes)],
                ['Par persona', $distribution['per_persona']],
                ['Par scam type', $distribution['per_scam_type']],
                ['Par combinaison', $distribution['per_combination']],
            ]
        );

        if (!$io->confirm('Voulez-vous continuer ?', true)) {
            return Command::SUCCESS;
        }

        // Générer les conversations
        $io->section('🔧 Génération des conversations');
        $this->logger->info('[CMD] Section started, creating progress bar');

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat('debug');
        $this->logger->info('[CMD] Progress bar created, starting...');
        $progressBar->start();
        $this->logger->info('[CMD] Progress bar started');

        $generated = 0;
        $errors = 0;
        $startTime = microtime(true);

        // Créer un plan de génération distribué
        $this->logger->info('[CMD] Creating generation plan', ['count' => $count]);
        $plan = $this->createGenerationPlan($count, $personas, $scamTypes, $channels);
        $this->logger->info('[CMD] Plan created', ['plan_size' => count($plan)]);

        /** @var int<1, max> $validBatchSize */
        $validBatchSize = max(1, $batchSize);
        $this->logger->info('[CMD] Starting batch processing', ['batch_size' => $validBatchSize]);

        foreach (array_chunk($plan, $validBatchSize) as $batchIndex => $batch) {
            $this->logger->info('[CMD] Processing batch', ['batch_index' => $batchIndex, 'batch_size' => count($batch)]);

            foreach ($batch as $itemIndex => $item) {
                // Re-fetch entities by ID (survives EntityManager::clear())
                $persona = $this->em->getRepository(Persona::class)->find($item['persona_id']);
                $scamType = $this->em->getRepository(ScamType::class)->find($item['scam_type_id']);
                $channel = $this->em->getRepository(Channel::class)->find($item['channel_id']);

                if (!$persona || !$scamType || !$channel) {
                    $errors++;
                    $this->logger->error('Failed to fetch entities', [
                        'persona_id' => $item['persona_id'],
                        'scam_type_id' => $item['scam_type_id'],
                        'channel_id' => $item['channel_id'],
                    ]);

                    continue;
                }

                $this->logger->info('[CMD] Starting conversation generation', [
                    'batch_index' => $batchIndex,
                    'item_index' => $itemIndex,
                    'persona' => $persona->getPersonaCode(),
                    'scam_type' => $scamType->getCode(),
                ]);

                try {
                    $this->logger->info('[CMD] Calling generator->generateConversation()...');
                    $conversation = $this->generator->generateConversation(
                        scamType: $scamType,
                        persona: $persona,
                        channel: $channel,
                        messageCount: $item['message_count']
                    );
                    $this->logger->info('[CMD] Conversation generated successfully');

                    $generated++;
                    $progressBar->advance();
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('Failed to generate conversation', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'persona' => $persona->getPersonaCode(),
                        'scam_type' => $scamType->getCode(),
                    ]);
                }
            }

            // ConversationGenerator already flushes internally, just clear for memory
            if (!$dryRun) {
                $this->em->clear();
            }

            // Log progress every 10 batches
            if ($batchIndex % 10 === 0) {
                $this->logger->info('Generation progress', [
                    'generated' => $generated,
                    'errors' => $errors,
                    'batch' => $batchIndex,
                ]);
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $duration = microtime(true) - $startTime;

        // Statistiques finales
        $io->section('✅ Génération terminée');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Conversations générées', number_format($generated)],
                ['Erreurs', number_format($errors)],
                ['Durée', sprintf('%.2f secondes', $duration)],
                ['Vitesse', sprintf('%.2f conv/sec', $generated / $duration)],
                ['Mode', $dryRun ? 'DRY-RUN' : 'PRODUCTION'],
            ]
        );

        if ($errors > 0) {
            $io->warning(sprintf('%d conversations ont échoué (voir les logs)', $errors));
        }

        return Command::SUCCESS;
    }

    /**
     * Calcule la distribution des conversations
     *
     * @return array{per_persona: int, per_scam_type: int, per_combination: int}
     */
    private function calculateDistribution(int $total, int $personaCount, int $scamTypeCount): array
    {
        $combinations = $personaCount * $scamTypeCount;

        return [
            'per_persona' => (int) ceil($total / $personaCount),
            'per_scam_type' => (int) ceil($total / $scamTypeCount),
            'per_combination' => (int) ceil($total / $combinations),
        ];
    }

    /**
     * Crée un plan de génération distribué uniformément
     *
     * Uses entity IDs instead of entity references to survive EntityManager::clear()
     *
     * @param int        $count     Nombre total de conversations
     * @param Persona[]  $personas  Liste des personas
     * @param ScamType[] $scamTypes Liste des scam types
     * @param Channel[]  $channels  Liste des channels
     *
     * @return array<int, array{persona_id: int, scam_type_id: int, channel_id: int, message_count: int}> Plan de génération
     */
    private function createGenerationPlan(int $count, array $personas, array $scamTypes, array $channels): array
    {
        $plan = [];

        // Créer toutes les combinaisons possibles (using IDs)
        $combinations = [];

        foreach ($personas as $persona) {
            foreach ($scamTypes as $scamType) {
                $combinations[] = [
                    'persona_id' => $persona->getPersonaId(),
                    'scam_type_id' => $scamType->getScamTypeId(),
                ];
            }
        }

        // Mélanger pour distribution aléatoire
        shuffle($combinations);

        // Collect channel IDs
        $channelIds = array_map(fn (Channel $c) => $c->getChannelId(), $channels);

        // Distribuer les conversations
        $combinationIndex = 0;

        for ($i = 0; $i < $count; $i++) {
            $combination = $combinations[$combinationIndex % count($combinations)];

            $plan[] = [
                'persona_id' => $combination['persona_id'],
                'scam_type_id' => $combination['scam_type_id'],
                'channel_id' => $channelIds[array_rand($channelIds)],
                'message_count' => rand(self::MIN_MESSAGES, self::MAX_MESSAGES),
            ];

            $combinationIndex++;
        }

        // Mélanger le plan final pour plus de variété
        shuffle($plan);

        return $plan;
    }
}
