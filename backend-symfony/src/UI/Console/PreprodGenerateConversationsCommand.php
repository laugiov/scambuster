<?php

declare(strict_types=1);

namespace App\UI\Console;

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
    description: 'Generate 10,000 realistic scam conversations for preprod'
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
            ->addOption('count', 'c', InputOption::VALUE_OPTIONAL, 'Number of conversations to generate', self::TOTAL_CONVERSATIONS)
            ->addOption('batch-size', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', self::BATCH_SIZE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation without saving')
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

        $io->title('Generating scam conversations for preprod');

        if ($dryRun) {
            $io->warning('DRY-RUN mode: no data will be saved');
        }

        // Load reference entities
        $io->section('Loading reference data');

        $personas = $this->em->getRepository(Persona::class)->findBy(['isActive' => true]);
        $scamTypes = $this->em->getRepository(ScamType::class)->findBy(['active' => true]);
        $channels = $this->em->getRepository(Channel::class)->findAll();

        if ($personas === [] || $scamTypes === [] || $channels === []) {
            $io->error('Missing reference data. Ensure personas, scam_types and channels are loaded.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Loaded: %d personas, %d scam types, %d channels',
            count($personas),
            count($scamTypes),
            count($channels)
        ));

        // Calculate distribution
        $distribution = $this->calculateDistribution($count, count($personas), count($scamTypes));

        $io->section('📈 Conversation distribution');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Total conversations', number_format($count)],
                ['Personas', count($personas)],
                ['Scam types', count($scamTypes)],
                ['Per persona', $distribution['per_persona']],
                ['Per scam type', $distribution['per_scam_type']],
                ['Per combination', $distribution['per_combination']],
            ]
        );

        if (!$io->confirm('Do you want to continue?', true)) {
            return Command::SUCCESS;
        }

        // Generate conversations
        $io->section('Generating conversations');
        $this->logger->info('[CMD] Section started, creating progress bar');

        $progressBar = new ProgressBar($output, $count);
        $progressBar->setFormat('debug');
        $this->logger->info('[CMD] Progress bar created, starting...');
        $progressBar->start();
        $this->logger->info('[CMD] Progress bar started');

        $generated = 0;
        $errors = 0;
        $startTime = microtime(true);

        // Create a distributed generation plan
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

        // Final statistics
        $io->section('Generation completed');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Conversations generated', number_format($generated)],
                ['Errors', number_format($errors)],
                ['Duration', sprintf('%.2f seconds', $duration)],
                ['Speed', sprintf('%.2f conv/sec', $generated / $duration)],
                ['Mode', $dryRun ? 'DRY-RUN' : 'PRODUCTION'],
            ]
        );

        if ($errors > 0) {
            $io->warning(sprintf('%d conversations failed (see logs)', $errors));
        }

        return Command::SUCCESS;
    }

    /**
     * Computes the conversation distribution
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
     * Creates a uniformly distributed generation plan
     *
     * Uses entity IDs instead of entity references to survive EntityManager::clear()
     *
     * @param int        $count     Total number of conversations
     * @param Persona[]  $personas  List of personas
     * @param ScamType[] $scamTypes List of scam types
     * @param Channel[]  $channels  List of channels
     *
     * @return array<int, array{persona_id: int, scam_type_id: int, channel_id: int, message_count: int}> Generation plan
     */
    private function createGenerationPlan(int $count, array $personas, array $scamTypes, array $channels): array
    {
        $plan = [];

        // Create all possible combinations (using IDs)
        $combinations = [];

        foreach ($personas as $persona) {
            foreach ($scamTypes as $scamType) {
                $combinations[] = [
                    'persona_id' => $persona->getPersonaId(),
                    'scam_type_id' => $scamType->getScamTypeId(),
                ];
            }
        }

        // Shuffle for random distribution
        shuffle($combinations);

        // Collect channel IDs
        $channelIds = array_map(fn (Channel $c): int => $c->getChannelId(), $channels);

        // Distribute the conversations
        $combinationIndex = 0;

        for ($i = 0; $i < $count; $i++) {
            $combination = $combinations[$combinationIndex % count($combinations)];

            $plan[] = [
                'persona_id' => $combination['persona_id'],
                'scam_type_id' => $combination['scam_type_id'],
                'channel_id' => $channelIds[array_rand($channelIds)],
                'message_count' => random_int(self::MIN_MESSAGES, self::MAX_MESSAGES),
            ];

            $combinationIndex++;
        }

        // Shuffle final plan for more variety
        shuffle($plan);

        return $plan;
    }
}
