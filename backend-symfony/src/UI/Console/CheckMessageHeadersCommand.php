<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:check-message-headers',
    description: 'Check message headers for a specific message',
)]
class CheckMessageHeadersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('msg_id', InputArgument::REQUIRED, 'Message ID to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $msgId = $input->getArgument('msg_id');

        $message = $this->em->getRepository(\App\Domain\Communication\Message::class)->find($msgId);

        if ($message === null) {
            $output->writeln('<error>Message not found</error>');

            return Command::FAILURE;
        }

        $output->writeln("Message ID: <info>{$message->getMsgId()}</info>");
        $output->writeln("Direction: {$message->getDirection()->getCode()}");
        $output->writeln("Subject: {$message->getSubject()}");
        $output->writeln("Timestamp: {$message->getTsMsg()->format('Y-m-d H:i:s')}");
        $output->writeln('');

        $headers = $message->getHeaders();

        $output->writeln('<info>=== Important Headers ===</info>');
        /** @var string $messageIdHeader */
        $messageIdHeader = $headers['message-id'] ?? '<none>';
        $output->writeln('Message-ID: ' . $messageIdHeader);
        /** @var string $inReplyToHeader */
        $inReplyToHeader = $headers['in-reply-to'] ?? '<none>';
        $output->writeln('In-Reply-To: ' . $inReplyToHeader);
        /** @var string $referencesHeader */
        $referencesHeader = $headers['references'] ?? '<none>';
        $output->writeln('References: ' . $referencesHeader);
        /** @var string $threadIdHeader */
        $threadIdHeader = $headers['thread_id'] ?? '<none>';
        $output->writeln('Thread-ID: ' . $threadIdHeader);
        /** @var string $sendStatusHeader */
        $sendStatusHeader = $headers['send_status'] ?? '<none>';
        $output->writeln('Send Status: ' . $sendStatusHeader);
        $output->writeln('');

        $output->writeln('<info>=== All Headers (JSON) ===</info>');
        $output->writeln((string) json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Check if this is a reply
        $replyTo = $message->getReplyTo();

        if ($replyTo) {
            $output->writeln('');
            $output->writeln('<info>=== Reply To (Internal) ===</info>');
            $output->writeln("Reply To Message ID: {$replyTo->getMsgId()}");
            $output->writeln("Reply To Subject: {$replyTo->getSubject()}");
            $parentHeaders = $replyTo->getHeaders();
            /** @var string $parentMessageId */
            $parentMessageId = $parentHeaders['message-id'] ?? '<none>';
            $output->writeln('Parent Message-ID: ' . $parentMessageId);
        }

        return Command::SUCCESS;
    }
}
