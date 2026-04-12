<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\ThreadingAnalyzer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:analyze-threading',
    description: 'Analyze message threading by subject',
)]
class AnalyzeThreadingCommand extends Command
{
    public function __construct(
        private readonly ThreadingAnalyzer $threadingAnalyzer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('subject_pattern', InputArgument::REQUIRED, 'Subject pattern to search for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $subjectPattern */
        $subjectPattern = $input->getArgument('subject_pattern');

        $output->writeln("Analyzing threading for subject pattern: <info>{$subjectPattern}</info>");
        $output->writeln('');

        $messages = $this->threadingAnalyzer->findMessagesBySubjectPattern($subjectPattern);

        if (empty($messages)) {
            $output->writeln('<error>No messages found</error>');

            return Command::FAILURE;
        }

        $output->writeln('Found <info>' . count($messages) . '</info> messages');
        $output->writeln('');

        $conversations = $this->threadingAnalyzer->groupByConversation($messages);

        $output->writeln('Messages are split across <error>' . count($conversations) . '</error> conversations');
        $output->writeln('');

        // Display each conversation
        foreach ($conversations as $convId => $msgs) {
            $output->writeln("=== Conversation: <comment>{$convId}</comment> ===");
            $output->writeln('Messages: ' . count($msgs));
            $output->writeln('');

            $table = new Table($output);
            $table->setHeaders(['Msg ID (first 8)', 'Direction', 'Timestamp', 'Reply To (first 8)', 'Message-ID', 'In-Reply-To', 'Thread-ID']);

            foreach ($msgs as $msg) {
                /** @var string $msgId */
                $msgId = $msg['msg_id'];
                /** @var string|null $replyToMsgId */
                $replyToMsgId = $msg['reply_to_msg_id'];
                $table->addRow([
                    substr($msgId, 0, 8),
                    $msg['direction'],
                    $msg['ts_msg'],
                    $replyToMsgId ? substr($replyToMsgId, 0, 8) : 'NULL',
                    $msg['message_id'] ?: 'NULL',
                    $msg['in_reply_to'] ?: 'NULL',
                    $msg['thread_id'] ?: 'NULL',
                ]);
            }

            $table->render();
            $output->writeln('');

            // Show threading analysis
            $output->writeln('<info>Threading Analysis:</info>');

            /** @var array<string, string|null> $msg */
            foreach ($msgs as $msg) {
                $output->writeln('  - Message ' . ($msg['msg_id'] ?? '') . ' (dir=' . ($msg['direction'] ?? '') . '):');
                $output->writeln('      Message-ID: ' . ($msg['message_id'] ?: 'NONE'));
                $output->writeln('      In-Reply-To: ' . ($msg['in_reply_to'] ?: 'NONE'));
                $output->writeln('      References: ' . ($msg['references'] ?: 'NONE'));
                $output->writeln('      Thread-ID: ' . ($msg['thread_id'] ?: 'NONE'));
                $output->writeln('      Reply-To (internal): ' . ($msg['reply_to_msg_id'] ?: 'NONE'));
                $output->writeln('');
            }

            $output->writeln('---');
            $output->writeln('');
        }

        // Provide recommendation
        $output->writeln('<info>Recommendations:</info>');

        if (count($conversations) > 1) {
            $output->writeln('<error>ISSUE FOUND: Messages should be in ONE conversation, not ' . count($conversations) . '</error>');
            $output->writeln('');
            $output->writeln('Possible causes:');
            $output->writeln('  1. First message (direction=1) has no Message-ID header');
            $output->writeln('  2. Reply message (direction=2) has no In-Reply-To or References headers');
            $output->writeln('  3. Message-ID format mismatch (with/without angle brackets)');
            $output->writeln('  4. IngestHandler failed to find parent message during ingestion');
        } else {
            $output->writeln('<info>✓ All messages are correctly grouped in one conversation</info>');
        }

        return Command::SUCCESS;
    }
}
