<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\IocHandler;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrate existing messages to extract header-based IOCs.
 *
 * Extracts 5 header IOC types from existing messages:
 * - message_id
 * - subject
 * - spf_result
 * - dkim_result
 * - dmarc_result
 *
 * This command is idempotent - it will skip IOCs that already exist.
 */
#[AsCommand(
    name: 'app:migrate-header-iocs',
    description: 'Extract header-based IOCs from existing messages'
)]
final class MigrateHeaderIocsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocHandler $iocHandler
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating messages to extract header IOCs');

        // Find all messages with headers
        /** @var list<Message> $messages */
        $messages = $this->em->getRepository(Message::class)
            ->createQueryBuilder('m')
            ->where('m.headers IS NOT NULL')
            ->andWhere('m.deletedAt IS NULL')
            ->getQuery()
            ->getResult();

        $totalMessages = count($messages);

        if ($totalMessages === 0) {
            $io->warning('No messages with headers found');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d messages to process', $totalMessages));

        $totalExtracted = 0;
        $messagesProcessed = 0;
        $messagesSkipped = 0;
        $errors = 0;

        $io->progressStart($totalMessages);

        foreach ($messages as $message) {
            try {
                $count = $this->iocHandler->extractAndUpsertHeaderIocs($message);
                $totalExtracted += $count;

                if ($count > 0) {
                    ++$messagesProcessed;
                } else {
                    ++$messagesSkipped;
                }
            } catch (\Exception $e) {
                ++$errors;
                // Continue processing other messages
            }

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Display summary
        $io->success('Migration completed');

        $io->table(
            ['Metric', 'Count'],
            [
                ['Total messages scanned', $totalMessages],
                ['Messages processed', $messagesProcessed],
                ['Messages skipped (no headers)', $messagesSkipped],
                ['Total header IOCs extracted', $totalExtracted],
                ['Errors', $errors],
            ]
        );

        return Command::SUCCESS;
    }
}
