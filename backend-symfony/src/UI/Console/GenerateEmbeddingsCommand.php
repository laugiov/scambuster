<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\EmbeddingService;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-embeddings',
    description: 'Generate semantic embeddings for inbound messages without vectors',
)]
final class GenerateEmbeddingsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EmbeddingService $embeddingService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max messages to process', '500')
            ->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Batch size for API calls', '50')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count messages without processing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $rawLimit */
        $rawLimit = $input->getOption('limit');
        $limit = (int) $rawLimit;
        /** @var string $rawBatch */
        $rawBatch = $input->getOption('batch-size');
        $batchSize = (int) $rawBatch;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Generate Message Embeddings' . ($dryRun ? ' [DRY RUN]' : ''));

        // Find messages without embeddings
        $rows = $this->connection->fetchAllAssociative(
            "SELECT m.msg_id, LEFT(m.body_text, 30000) as body
             FROM message m
             WHERE m.vector_id IS NULL AND m.direction = 3 AND m.body_text IS NOT NULL AND LENGTH(m.body_text) > 20
             ORDER BY m.ts_msg DESC
             LIMIT {$limit}",
        );

        $total = count($rows);

        if ($total === 0) {
            $io->success('No messages need embeddings.');

            return Command::SUCCESS;
        }

        $io->text(sprintf('Found %d messages without embeddings (model: %s, dim: %d)', $total, $this->embeddingService->getModel(), $this->embeddingService->getDimensions()));

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d messages would be processed. Estimated cost: $%.4f', $total, $total * 200 * 0.02 / 1_000_000));

            return Command::SUCCESS;
        }

        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');

        $embedded = 0;
        $errors = 0;

        // Process in batches
        $batches = array_chunk($rows, max(1, $batchSize));

        foreach ($batches as $batch) {
            /** @var array<int, array{msg_id: string, body: string}> $batch */
            $texts = array_map(fn (array $row): string => $row['body'], $batch);
            $msgIds = array_map(fn (array $row): string => $row['msg_id'], $batch);

            $embeddings = $this->embeddingService->generateBatch($texts);

            if ($embeddings === []) {
                $errors += count($batch);
                $progressBar->advance(count($batch));

                continue;
            }

            foreach ($embeddings as $i => $embedding) {
                if (!isset($msgIds[$i])) {
                    continue;
                }

                // Never persist an empty/dimensionless vector — count it as an
                // error and skip, so a partial provider failure cannot store
                // unusable rows.
                if ($embedding === []) {
                    ++$errors;

                    continue;
                }

                $vectorId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

                $this->connection->executeStatement(
                    'INSERT INTO message_vector (vector_id, embedding, model_name, dim, ts_created)
                     VALUES (:vectorId, :embedding, :modelName, :dim, NOW())',
                    [
                        'vectorId' => $vectorId,
                        'embedding' => json_encode($embedding),
                        'modelName' => $this->embeddingService->getModel(),
                        // Record the ACTUAL vector length, not a configured guess:
                        // local models emit their own dimension (provider-agnostic).
                        'dim' => count($embedding),
                    ],
                );

                $this->connection->executeStatement(
                    'UPDATE message SET vector_id = :vectorId WHERE msg_id = :msgId',
                    ['vectorId' => $vectorId, 'msgId' => $msgIds[$i]],
                );

                ++$embedded;
            }

            $progressBar->advance(count($batch));
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Embedded %d messages (%d errors)', $embedded, $errors));

        return Command::SUCCESS;
    }
}
