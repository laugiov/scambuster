<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\ReplyHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TestReplyGenerateCommand extends Command
{
    public function __construct(
        private ReplyHandler $replyHandler
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('app:test:reply-generate')
            ->setDescription('Test generateReply() to verify parent_gmail_msg_id is returned');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Test message ID from previous test
        $replyMsgId = '5be3b2d6-2ffe-4125-9c33-404393018d8b';

        $io->title('Testing GET /reply/{msgId} endpoint logic');
        $io->text("Reply Msg ID: $replyMsgId");
        $io->newLine();

        try {
            // Get the message
            $message = $this->replyHandler->getMessage($replyMsgId);

            if (!$message) {
                $io->error('Message not found!');

                return Command::FAILURE;
            }

            // Simulate what the controller does
            $parentGmailMsgId = null;
            $parentMessage = $message->getReplyTo();

            $io->text('Parent Message: ' . ($parentMessage ? $parentMessage->getMsgId() : 'null'));

            if ($parentMessage) {
                $parentGmailMsgId = $parentMessage->getProviderMsgId();
            }

            $io->newLine();
            $io->section('Result:');
            $io->text('parent_gmail_msg_id: ' . ($parentGmailMsgId ?? 'null'));

            if ($parentGmailMsgId) {
                $io->success('✅ parent_gmail_msg_id is present: ' . $parentGmailMsgId);

                return Command::SUCCESS;
            } else {
                $io->error('❌ parent_gmail_msg_id is MISSING!');

                return Command::FAILURE;
            }

        } catch (\Exception $e) {
            $io->error('Failed: ' . $e->getMessage());
            $io->text($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
