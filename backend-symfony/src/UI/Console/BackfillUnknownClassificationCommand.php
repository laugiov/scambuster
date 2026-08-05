<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\ScamClassificationHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-classify the recent past's UNKNOWN conversations now that the
 * taxonomy has grown (e.g. COLD_SERVICE_SPAM).
 *
 * Scope is deliberately narrow: conversations created within the trailing
 * N days (default 31) whose current type is UNKNOWN and that are not
 * soft-deleted. Nothing else is ever examined.
 *
 * Preview by default: it reports, per in-scope conversation, the proposed
 * new type and confidence, and CHANGES NOTHING. Re-classification is only
 * persisted with the explicit --apply flag — the local database holds real
 * data, so an accidental run must be a no-op.
 *
 * Exit codes: 0 on completion.
 */
#[AsCommand(
    name: 'scambuster:classify:backfill-unknown',
    description: 'Preview (default) or apply re-classification of last-month UNKNOWN conversations.',
)]
final class BackfillUnknownClassificationCommand extends Command
{
    private const DEFAULT_DAYS = 31;

    public function __construct(
        private readonly ScamClassificationHandler $handler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the re-classifications. Without this flag the command only previews and changes nothing.')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Trailing window in days (conversations created within this many days).', (string) self::DEFAULT_DAYS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Optional cap on the number of conversations processed.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $daysOpt = $input->getOption('days');
        $days = is_numeric($daysOpt) ? max(1, (int) $daysOpt) : self::DEFAULT_DAYS;
        $limitOpt = $input->getOption('limit');
        $limit = is_numeric($limitOpt) ? max(1, (int) $limitOpt) : null;

        $convIds = $this->handler->findRecentUnknownConversationIds($days, $limit);

        $io->title(sprintf(
            'Backfill UNKNOWN classifications — %s | window: last %d days | scope: %d conversation(s)',
            $apply ? 'APPLY (writes)' : 'PREVIEW (no writes)',
            $days,
            count($convIds),
        ));

        if ($convIds === []) {
            $io->success('No in-scope conversations. Nothing to do.');

            return Command::SUCCESS;
        }

        /** @var array<string, int> $summary */
        $summary = [];
        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($convIds as $convId) {
            try {
                if ($apply) {
                    // autoClassifyConversation re-runs the classifier and
                    // persists via the audited path; it keeps the conv
                    // UNKNOWN when the proposal is UNKNOWN or below threshold.
                    $result = $this->handler->autoClassifyConversation($convId);
                    $proposed = $result['scam_type_code'];
                    $confidence = $result['confidence'];
                } else {
                    $preview = $this->handler->previewClassifyConversation($convId);

                    if ($preview === null) {
                        ++$skipped;
                        $io->writeln(sprintf('  <comment>skip</comment>   %s — no messages', $convId));

                        continue;
                    }
                    $proposed = $preview->scamTypeCode;
                    $confidence = $preview->confidence;
                }

                $proposedUpper = strtoupper($proposed);
                $summary[$proposedUpper] = ($summary[$proposedUpper] ?? 0) + 1;

                if ($proposedUpper === 'UNKNOWN') {
                    $io->writeln(sprintf('  no-chg  %s — stays UNKNOWN (%.2f)', $convId, $confidence));

                    continue;
                }

                ++$changed;
                $verb = $apply ? 'applied' : 'would set';
                $io->writeln(sprintf('  <info>%s</info> %s — UNKNOWN → %s (%.2f)', $verb, $convId, $proposedUpper, $confidence));
            } catch (\Throwable $e) {
                ++$failed;
                $io->writeln(sprintf('  <error>fail</error>   %s — %s', $convId, $e->getMessage()));
            }
        }

        $io->newLine();
        $io->section('Summary');
        $io->definitionList(
            ['Mode' => $apply ? 'apply (persisted)' : 'preview (no writes)'],
            ['In scope' => count($convIds)],
            [($apply ? 'Reclassified' : 'Would reclassify') => $changed],
            ['Stayed UNKNOWN' => ($summary['UNKNOWN'] ?? 0)],
            ['Skipped (no messages)' => $skipped],
            ['Failed' => $failed],
        );

        arsort($summary);

        foreach ($summary as $type => $count) {
            $io->writeln(sprintf('  %-24s %d', $type, $count));
        }

        if (!$apply && $changed > 0) {
            $io->newLine();
            $io->note('Preview only — nothing was changed. Re-run with --apply to persist.');
        }

        return Command::SUCCESS;
    }
}
