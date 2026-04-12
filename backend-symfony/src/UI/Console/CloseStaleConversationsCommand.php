<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\ConversationLifecycleConfig;
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

/**
 * Close stale conversations using per-scam-type lifecycle policies.
 *
 * Three closure criteria (any one triggers closure):
 * 1. Inactivity timeout: tsLast < NOW() - policy.timeout_hours
 * 2. Max turns: turnsCount >= policy.max_turns
 * 3. Max duration: tsFirst < NOW() - policy.max_duration_days
 *
 * The --days option overrides per-type timeouts with a global value.
 */
#[AsCommand(
    name: 'app:close-stale-conversations',
    description: 'Close conversations based on per-scam-type lifecycle policies'
)]
class CloseStaleConversationsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationClosureService $closureService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Override: global inactivity days for all types (ignores per-type policies)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be closed without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string|null $daysOption */
        $daysOption = $input->getOption('days');
        $globalDays = $daysOption !== null ? (int) $daysOption : null;
        $dryRun = (bool) $input->getOption('dry-run');

        if ($globalDays !== null && $globalDays < 1) {
            $io->error('Days must be >= 1');

            return Command::FAILURE;
        }

        if ($globalDays !== null) {
            $io->title(sprintf('Close stale conversations (global override: %d days)', $globalDays));
        } else {
            $io->title('Close stale conversations (per-scam-type policies)');
        }

        /** @var Conversation[] $conversations */
        $conversations = $this->em->getRepository(Conversation::class)->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('status', ConversationStatus::OPEN)
            ->getQuery()
            ->getResult();

        if (empty($conversations)) {
            $io->success('No open conversations found.');

            return Command::SUCCESS;
        }

        $toClose = [];
        $now = new \DateTimeImmutable();

        foreach ($conversations as $conv) {
            $scamTypeCode = $conv->getScamType()->getCode();
            $reason = $this->shouldClose($conv, $scamTypeCode, $now, $globalDays);

            if ($reason !== null) {
                $toClose[] = ['conv' => $conv, 'reason' => $reason];
            }
        }

        if (empty($toClose)) {
            $io->success(sprintf('Checked %d open conversations — none need closing.', count($conversations)));

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d conversations to close (out of %d open)', count($toClose), count($conversations)));

        if ($dryRun) {
            $io->table(
                ['Conversation ID', 'Last Activity', 'Scam Type', 'Persona', 'Reason', 'Policy Timeout'],
                array_map(static function (array $item): array {
                    /** @var Conversation $c */
                    $c = $item['conv'];
                    $scamCode = $c->getScamType()->getCode();

                    return [
                        $c->getConvId(),
                        $c->getTsLast()->format('Y-m-d H:i'),
                        $scamCode,
                        $c->getPersona()?->getPersonaCode() ?? 'none',
                        $item['reason'],
                        ConversationLifecycleConfig::getTimeoutHours($scamCode) . 'h',
                    ];
                }, $toClose)
            );
            $io->warning('Dry run — no changes made.');

            return Command::SUCCESS;
        }

        $convIds = array_map(static fn (array $item): string => $item['conv']->getConvId(), $toClose);
        $closed = $this->closureService->closeConversationsBatch($convIds);

        $io->table(
            ['Metric', 'Value'],
            [
                ['Open conversations checked', (string) count($conversations)],
                ['Identified for closure', (string) count($toClose)],
                ['Successfully closed', (string) $closed],
                ['Failed', (string) (count($toClose) - $closed)],
            ]
        );

        $io->success(sprintf('Closed %d conversations.', $closed));

        return Command::SUCCESS;
    }

    private function shouldClose(Conversation $conv, string $scamTypeCode, \DateTimeImmutable $now, ?int $globalDays): ?string
    {
        $policy = ConversationLifecycleConfig::getPolicy($scamTypeCode);

        // 1. Inactivity timeout
        $timeoutHours = $globalDays !== null ? $globalDays * 24 : $policy['timeout_hours'];
        $threshold = $now->modify(sprintf('-%d hours', $timeoutHours));

        if ($conv->getTsLast() < $threshold) {
            return sprintf('inactivity (>%dh)', $timeoutHours);
        }

        // 2. Max turns
        if ($conv->getTurnsCount() >= $policy['max_turns']) {
            return sprintf('max_turns (%d/%d)', $conv->getTurnsCount(), $policy['max_turns']);
        }

        // 3. Max duration
        $maxDurationThreshold = $now->modify(sprintf('-%d days', $policy['max_duration_days']));

        if ($conv->getTsFirst() < $maxDurationThreshold) {
            return sprintf('max_duration (>%d days)', $policy['max_duration_days']);
        }

        return null;
    }
}
