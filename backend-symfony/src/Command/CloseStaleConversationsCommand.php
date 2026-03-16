<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Scambaiting\ConversationClosureService;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:close-stale-conversations',
    description: 'Close conversations with no activity beyond the configured threshold'
)]
class CloseStaleConversationsCommand extends Command
{
    private const DEFAULT_STALE_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationClosureService $closureService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Days of inactivity before closing', (string) self::DEFAULT_STALE_DAYS)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be closed without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $daysOption */
        $daysOption = $input->getOption('days');
        $days = (int) $daysOption;
        $dryRun = $input->getOption('dry-run');

        if ($days < 1) {
            $io->error('Days must be >= 1');

            return Command::FAILURE;
        }

        $io->title(sprintf('Close stale conversations (inactive > %d days)', $days));

        $threshold = new \DateTimeImmutable(sprintf('-%d days', $days));

        $qb = $this->em->getRepository(Conversation::class)->createQueryBuilder('c');
        $conversations = $qb
            ->where('c.status = :status')
            ->andWhere('c.tsLast < :threshold')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', ConversationStatus::OPEN)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        if (empty($conversations)) {
            $io->success('No stale conversations found.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d stale conversations', count($conversations)));

        if ($dryRun) {
            $io->table(
                ['Conversation ID', 'Last Activity', 'Scam Type', 'Persona'],
                array_map(static function (mixed $c): array {
                    /** @var Conversation $c */
                    return [
                        $c->getConvId(),
                        $c->getTsLast()->format('Y-m-d H:i'),
                        $c->getScamType()->getCode(),
                        $c->getPersona()?->getPersonaCode() ?? 'none',
                    ];
                }, $conversations)
            );
            $io->warning('Dry run -- no changes made.');

            return Command::SUCCESS;
        }

        $convIds = array_map(
            static function (mixed $c): string {
                /** @var Conversation $c */
                return $c->getConvId();
            },
            $conversations
        );

        $closed = $this->closureService->closeConversationsBatch($convIds);

        $io->table(
            ['Metric', 'Value'],
            [
                ['Found', count($conversations)],
                ['Closed', $closed],
                ['Failed', count($conversations) - $closed],
                ['Threshold', sprintf('%d days (since %s)', $days, $threshold->format('Y-m-d'))],
            ]
        );

        $io->success(sprintf('Closed %d stale conversations.', $closed));

        return Command::SUCCESS;
    }
}
