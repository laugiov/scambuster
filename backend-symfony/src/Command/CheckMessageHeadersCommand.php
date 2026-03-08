<?php

declare(strict_types=1);

namespace App\Command;

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
        private EntityManagerInterface $em
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

        $message = $this->em->getRepository('App\Domain\Communication\Message')->find($msgId);

        if (!$message) {
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
        $output->writeln("Message-ID: " . ($headers['message-id'] ?? '<none>'));
        $output->writeln("In-Reply-To: " . ($headers['in-reply-to'] ?? '<none>'));
        $output->writeln("References: " . ($headers['references'] ?? '<none>'));
        $output->writeln("Thread-ID: " . ($headers['thread_id'] ?? '<none>'));
        $output->writeln("Send Status: " . ($headers['send_status'] ?? '<none>'));
        $output->writeln('');

        $output->writeln('<info>=== All Headers (JSON) ===</info>');
        $output->writeln(json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Check if this is a reply
        $replyTo = $message->getReplyTo();
        if ($replyTo) {
            $output->writeln('');
            $output->writeln('<info>=== Reply To (Internal) ===</info>');
            $output->writeln("Reply To Message ID: {$replyTo->getMsgId()}");
            $output->writeln("Reply To Subject: {$replyTo->getSubject()}");
            $parentHeaders = $replyTo->getHeaders();
            $output->writeln("Parent Message-ID: " . ($parentHeaders['message-id'] ?? '<none>'));
        }

        return Command::SUCCESS;
    }
}
