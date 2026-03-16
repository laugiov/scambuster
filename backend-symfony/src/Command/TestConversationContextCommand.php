<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Communication\ReplyHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:test-conversation-context',
    description: 'Test conversation context retrieval',
)]
class TestConversationContextCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private ReplyHandler $replyHandler
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('conv_id', InputArgument::REQUIRED, 'Conversation ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $convId */
        $convId = $input->getArgument('conv_id');

        $output->writeln("Testing conversation: {$convId}");
        $output->writeln('');

        // Check database directly
        $conv = $this->em->getRepository('App\Domain\Communication\Conversation')->find($convId);

        if (!$conv) {
            $output->writeln('<error>Conversation not found</error>');

            return Command::FAILURE;
        }

        $scamType = $conv->getScamType();
        $output->writeln('=== Direct DB Query ===');
        $output->writeln("ScamType ID: {$scamType->getScamTypeId()}");
        $output->writeln("ScamType code: {$scamType->getCode()}");
        $output->writeln("Personas count: {$scamType->getPersonas()->count()}");

        $persona = $conv->getPersona();

        if ($persona) {
            $output->writeln("Conversation Persona ID: {$persona->getPersonaId()}");
            $output->writeln("Conversation Persona code: {$persona->getPersonaCode()}");
        } else {
            $output->writeln('Conversation Persona is NULL (will be assigned on first context call)');
        }

        // Test ReplyHandler method
        $context = $this->replyHandler->getConversationContext($convId);

        $output->writeln('');
        $output->writeln('=== ReplyHandler getConversationContext ===');
        /** @var array<string, mixed> $scamTypeCtx */
        $scamTypeCtx = $context['scam_type'] ?? [];
        /** @var string $scamTypeCode */
        $scamTypeCode = $scamTypeCtx['code'] ?? 'unknown';
        /** @var string $personaCode */
        $personaCode = $context['persona'] ?? 'unknown';
        $output->writeln("ScamType code: {$scamTypeCode}");
        $output->writeln("Persona code: {$personaCode}");

        $output->writeln('');
        $output->writeln('=== Full Context ===');
        $output->writeln(json_encode($context, JSON_PRETTY_PRINT) ?: '{}');

        return Command::SUCCESS;
    }
}
