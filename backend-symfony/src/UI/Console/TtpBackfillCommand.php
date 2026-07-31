<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Ttp\Exception\TtpExtractionDisabledException;
use App\Application\Ttp\TtpHandler;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill TTP observations over historical inbound messages.
 *
 * Preview by default: without --apply the command runs the real extractor with
 * persistence disabled, so it reports what WOULD be found and writes nothing —
 * an accidental run against production data is a no-op. Only --apply persists.
 *
 * Scope is the set of inbound, non-soft-deleted messages that belong to a
 * non-soft-deleted conversation and do NOT yet carry a TTP observation. That
 * "without an observation" filter makes the command resumable: a re-run skips
 * every message that already produced at least one observation, so a batch
 * interrupted by an error or a budget stop continues where it stopped.
 * CAVEAT: presence of an observation is the only processed-marker, so a message
 * the extractor analyzed but found no TTP in (a zero-yield message) carries no
 * row and therefore re-enters scope on a later run — it is analyzed (and billed)
 * again. A single full pass is unaffected; this only costs extra across resumes,
 * and always within --budget-usd. (A processed-at sentinel would make this
 * exact; tracked as a follow-up.) --force flips the scope to include
 * already-observed messages and deletes their observations before recomputing
 * (used when the prompt or taxonomy version changes).
 *
 * Cost is read from the llm_usage journal the extractor writes through
 * (purpose 'ttp_extraction'): a baseline is taken at start and the running
 * spend is the delta from it, so --budget-usd stops the loop once the real
 * spend since the command began exceeds the cap.
 */
#[AsCommand(
    name: 'scambuster:ttp:backfill',
    description: 'Preview (default) or apply TTP extraction over historical inbound messages.',
)]
final class TtpBackfillCommand extends Command
{
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly TtpHandler $ttpHandler,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the observations. Without this flag the command only previews and writes nothing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of inbound messages to process.', (string) self::DEFAULT_LIMIT)
            ->addOption('conv-days', null, InputOption::VALUE_REQUIRED, 'Only messages in conversations active within the last N days (default: all).', null)
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Stop once the real ttp_extraction spend since the command started exceeds this cap (default: no cap).', null)
            ->addOption('force', null, InputOption::VALUE_NONE, 'Recompute: include already-observed messages and delete their observations before re-extracting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apply = (bool) $input->getOption('apply');
        $force = (bool) $input->getOption('force');

        $limitRaw = $input->getOption('limit');
        $limit = is_numeric($limitRaw) ? max(1, (int) $limitRaw) : self::DEFAULT_LIMIT;

        $convDaysRaw = $input->getOption('conv-days');
        $convDays = is_numeric($convDaysRaw) ? max(1, (int) $convDaysRaw) : null;

        $budgetRaw = $input->getOption('budget-usd');
        $budgetUsd = is_numeric($budgetRaw) ? max(0.0, (float) $budgetRaw) : null;

        $messages = $this->findScope($limit, $convDays, $force);

        $io->title(sprintf(
            'TTP backfill — %s | scope: %d message(s)%s%s',
            $apply ? 'APPLY (writes)' : 'PREVIEW (no writes)',
            count($messages),
            $convDays !== null ? sprintf(' | active last %d day(s)', $convDays) : '',
            $force ? ' | force (recompute)' : '',
        ));

        if ($messages === []) {
            $io->success('No in-scope messages. Nothing to do.');

            return Command::SUCCESS;
        }

        $baselineCost = $this->cumulativeTtpCostUsd();

        $processed = 0;
        $observationsFound = 0;
        $persisted = 0;
        $confirmed = 0;
        $review = 0;
        $failed = 0;
        $budgetStopped = false;
        $remaining = 0;
        /** @var array<string, int> $distribution */
        $distribution = [];

        foreach ($messages as $index => $msgId) {
            try {
                if ($apply && $force) {
                    // Recompute path: clear the message's prior observations so the
                    // idempotent upsert can re-insert the fresh extraction.
                    $this->connection->executeStatement(
                        'DELETE FROM ttp_observation WHERE msg_id = :msgId',
                        ['msgId' => $msgId]
                    );
                }

                $result = $this->ttpHandler->extractForMessage($msgId, $apply);
            } catch (TtpExtractionDisabledException) {
                // The module is off for this deployment: nothing downstream can run,
                // so abort the whole batch rather than counting every message as a failure.
                $io->error('TTP extraction is disabled (TTP_EXTRACTION_ENABLED=false). Enable the module before backfilling.');

                return Command::FAILURE;
            } catch (\Throwable $e) {
                // One bad message must never abort the batch. The inbound-only scope
                // means the handler's OutgoingMessageException cannot occur here, but
                // this \Throwable catch would absorb it defensively all the same.
                ++$failed;
                $this->logger->error('[TtpBackfill] Failed to process message', [
                    'msg_id' => $msgId,
                    'error' => $e->getMessage(),
                ]);
                $io->warning(sprintf('Error processing message %s: %s', substr($msgId, 0, 8), $e->getMessage()));

                continue;
            }

            ++$processed;
            $observationsFound += $result['ttps_found'];
            $persisted += $result['persisted'];

            foreach ($result['observations'] as $observation) {
                $distribution[$observation['ttp_code']] = ($distribution[$observation['ttp_code']] ?? 0) + 1;

                if ($observation['status'] === 'confirmed') {
                    ++$confirmed;
                } else {
                    ++$review;
                }
            }

            if ($budgetUsd !== null) {
                $spent = $this->cumulativeTtpCostUsd() - $baselineCost;

                if ($spent > $budgetUsd) {
                    $budgetStopped = true;
                    $remaining = count($messages) - ($index + 1);
                    $io->warning(sprintf(
                        'Budget reached ($%.4f > $%.2f). Stopping: %d processed, %d remaining.',
                        $spent,
                        $budgetUsd,
                        $processed,
                        $remaining,
                    ));

                    break;
                }
            }
        }

        $totalCost = round($this->cumulativeTtpCostUsd() - $baselineCost, 6);

        $this->renderSummary($io, $apply, count($messages), $processed, $observationsFound, $persisted, $confirmed, $review, $failed, $distribution, $totalCost, $budgetUsd, $budgetStopped, $remaining);

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Resolve the deterministic set of in-scope message ids.
     *
     * @return list<string>
     */
    private function findScope(int $limit, ?int $convDays, bool $force): array
    {
        $sql = 'SELECT m.msg_id'
            . ' FROM message m'
            . " JOIN lkp_direction d ON d.dir_id = m.direction AND d.code = 'in'"
            . ' JOIN conversation c ON c.conv_id = m.conv_id AND c.deleted_at IS NULL';

        if (!$force) {
            // Default scope skips messages that already carry an observation, which
            // is what makes the command idempotent and resumable.
            $sql .= ' LEFT JOIN ttp_observation o ON o.msg_id = m.msg_id';
        }

        $sql .= ' WHERE m.deleted_at IS NULL';

        if (!$force) {
            $sql .= ' AND o.obs_id IS NULL';
        }

        $params = [];

        if ($convDays !== null) {
            $sql .= ' AND c.ts_last >= :cutoff';
            $params['cutoff'] = (new \DateTimeImmutable("-{$convDays} days"))->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY m.ts_msg ASC, m.msg_id ASC'
            . ' LIMIT ' . $limit;

        $rows = $this->connection->fetchFirstColumn($sql, $params);

        return array_values(array_filter(
            array_map(static fn (mixed $id): string => is_string($id) ? $id : '', $rows),
            static fn (string $id): bool => $id !== ''
        ));
    }

    /**
     * Real cumulative ttp_extraction spend recorded in the llm_usage journal.
     * The extractor's live LLM calls flow through the usage listener, so this
     * sum is the authoritative cost; a per-run delta from a start-of-run
     * baseline gives the spend since the command began.
     */
    private function cumulativeTtpCostUsd(): float
    {
        $raw = $this->connection->fetchOne(
            "SELECT COALESCE(SUM(estimated_cost_usd::numeric), 0) FROM llm_usage WHERE purpose = 'ttp_extraction'"
        );

        return is_numeric($raw) ? (float) $raw : 0.0;
    }

    /**
     * @param array<string, int> $distribution
     */
    private function renderSummary(
        SymfonyStyle $io,
        bool $apply,
        int $scope,
        int $processed,
        int $observationsFound,
        int $persisted,
        int $confirmed,
        int $review,
        int $failed,
        array $distribution,
        float $totalCost,
        ?float $budgetUsd,
        bool $budgetStopped,
        int $remaining,
    ): void {
        $io->newLine();
        $io->section('Summary');
        $io->definitionList(
            ['Mode' => $apply ? 'apply (persisted)' : 'preview (no writes)'],
            ['In scope' => $scope],
            ['Messages processed' => $processed],
            ['Observations found' => $observationsFound],
            ['Confirmed' => $confirmed],
            ['Review' => $review],
            ['Persisted' => $persisted],
            ['Failed' => $failed],
            ['Real cost (USD)' => sprintf('$%.4f', $totalCost)],
            ['Budget (USD)' => $budgetUsd !== null ? sprintf('$%.2f', $budgetUsd) : 'none'],
            ['Budget stopped' => $budgetStopped ? sprintf('yes (%d remaining)', $remaining) : 'no'],
        );

        if ($distribution !== []) {
            arsort($distribution);
            $io->section('TTP distribution');

            foreach ($distribution as $code => $count) {
                $io->writeln(sprintf('  %-12s %d', $code, $count));
            }
        }

        if (!$apply) {
            $io->newLine();
            $io->note('Preview only — no rows were written. Re-run with --apply to persist.');
        }
    }
}
