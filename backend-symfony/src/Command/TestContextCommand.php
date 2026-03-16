<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\ReplyHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-context',
    description: 'Test getConversationContext() pour debug'
)]
final class TestContextCommand extends Command
{
    public function __construct(
        private readonly ReplyHandler $replyHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('conv_id', InputArgument::REQUIRED, 'Conversation ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $convId */
        $convId = $input->getArgument('conv_id');

        $io->title("Testing getConversationContext() for conversation: {$convId}");

        try {
            $context = $this->replyHandler->getConversationContext($convId);

            if (!$context) {
                $io->error('Conversation not found');

                return Command::FAILURE;
            }

            $io->section('Raw Context Output');
            $io->writeln(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');

            /** @var array<string, mixed> $scamTypeCtx */
            $scamTypeCtx = $context['scam_type'] ?? [];

            $io->section('Key Fields');
            $io->table(
                ['Field', 'Value'],
                [
                    ['conv_id', $context['conv_id'] ?? 'NULL'],
                    ['status', $context['status'] ?? 'NULL'],
                    ['scam_type.code', $scamTypeCtx['code'] ?? 'NULL'],
                    ['scam_type.label_fr', $scamTypeCtx['label_fr'] ?? 'NULL'],
                    ['persona', $context['persona'] ?? 'NULL'],
                    ['message_count', count($context['last_messages'] ?? [])],
                    ['ioc_count', count($context['extracted_iocs'] ?? [])],
                ]
            );

            $io->success('Context retrieved successfully');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Error: ' . $e->getMessage());
            $io->writeln('Trace: ' . $e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
