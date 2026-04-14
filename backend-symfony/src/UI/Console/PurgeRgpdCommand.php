<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\PurgeService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:purge:rgpd',
    description: 'Purge conversations and messages according to RGPD rules.'
)]
class PurgeRgpdCommand extends Command
{
    public function __construct(private readonly PurgeService $purgeService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $soft = $this->purgeService->softDeleteOldOutboundConversations();
        $hard = $this->purgeService->hardDeleteOldInboundConversations();
        $output->writeln("<info>Soft-deleted outbound conversations: $soft</info>");
        $output->writeln("<info>Hard-deleted inbound conversations: $hard</info>");

        return Command::SUCCESS;
    }
}
