<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Communication\RiskScoreCalculator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 075d — Recalculate risk scores for all conversations using the current formula.
 *
 * Aggregates IOC types across all messages of each conversation, recomputes the
 * risk score via RiskScoreCalculator, and updates conversations where the score differs.
 */
#[AsCommand(
    name: 'app:fix:risk-scores',
    description: 'Recalculate risk scores for all conversations using current formula',
)]
final class RecalculateRiskScoresCommand extends Command
{
    public function __construct(
        private readonly Connection $conn,
        private readonly RiskScoreCalculator $calculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing')
            ->addOption('scam-type', null, InputOption::VALUE_REQUIRED, 'Filter by scam type code (e.g. CHARITY)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $scamTypeFilter = $input->getOption('scam-type');
        $scamTypeFilter = \is_string($scamTypeFilter) ? strtoupper($scamTypeFilter) : null;

        $io->title('Spec 075d — Recalculate risk scores');

        if ($dryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        // Fetch all conversations with their scam type
        $sql = 'SELECT c.conv_id, c.score_risk, st.code AS scam_code
                FROM conversation c
                JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id';

        $params = [];

        if ($scamTypeFilter !== null) {
            $sql .= ' WHERE st.code = :scamType';
            $params['scamType'] = $scamTypeFilter;
        }

        $sql .= ' ORDER BY c.conv_id';

        $conversations = $this->conn->fetchAllAssociative($sql, $params);

        if ($conversations === []) {
            $io->success('No conversations found.');

            return Command::SUCCESS;
        }

        $io->info(\sprintf('Processing %d conversations%s.', \count($conversations), $scamTypeFilter !== null ? ' (scam_type=' . $scamTypeFilter . ')' : ''));

        $updated = 0;
        $skipped = 0;
        /** @var list<array{conv_id: string, old: int, new: int}> $changes */
        $changes = [];

        foreach ($conversations as $conv) {
            $convId = \is_string($conv['conv_id'] ?? null) ? $conv['conv_id'] : '';
            $oldScore = \is_numeric($conv['score_risk'] ?? null) ? (int) $conv['score_risk'] : 0;
            $scamCode = \is_string($conv['scam_code'] ?? null) ? $conv['scam_code'] : 'UNKNOWN';

            // Aggregate IOC types from ALL messages of this conversation
            $iocRows = $this->conn->fetchAllAssociative(
                "SELECT i.type AS ioc_type, COUNT(*) AS cnt
                 FROM observed_ioc oi
                 JOIN message m ON oi.msg_id = m.msg_id
                 JOIN indicator i ON oi.indicator_id = i.indicator_id
                 JOIN lkp_direction d ON m.direction = d.dir_id
                 WHERE m.conv_id = :convId AND d.code = 'in'
                 GROUP BY i.type",
                ['convId' => $convId]
            );

            $iocTypes = [];
            $urlCount = 0;

            foreach ($iocRows as $iocRow) {
                $type = \is_string($iocRow['ioc_type'] ?? null) ? $iocRow['ioc_type'] : '';

                if ($type !== '') {
                    $iocTypes[$type] = true;

                    if ($type === 'url') {
                        $urlCount = \is_numeric($iocRow['cnt'] ?? null) ? (int) $iocRow['cnt'] : 0;
                    }
                }
            }

            $newScore = $this->calculator->compute($scamCode, $iocTypes, $urlCount);

            if ($newScore !== $oldScore) {
                if (!$dryRun) {
                    $this->conn->executeStatement(
                        'UPDATE conversation SET score_risk = :score WHERE conv_id = :convId',
                        ['score' => $newScore, 'convId' => $convId]
                    );
                }

                $changes[] = ['conv_id' => $convId, 'old' => $oldScore, 'new' => $newScore];
                ++$updated;
            } else {
                ++$skipped;
            }
        }

        $io->table(
            ['Metric', 'Count'],
            [
                ['Updated', $updated],
                ['Unchanged', $skipped],
            ]
        );

        if ($updated > 0 && $output->isVerbose()) {
            $sampleRows = \array_slice($changes, 0, 20);
            $io->section('Sample changes (first 20)');
            $io->table(
                ['conv_id', 'old_score', 'new_score'],
                array_map(fn (array $c): array => [substr($c['conv_id'], 0, 12) . '...', $c['old'], $c['new']], $sampleRows)
            );
        }

        if ($dryRun) {
            $io->success(\sprintf('Dry-run complete: %d would be updated, %d unchanged.', $updated, $skipped));
        } else {
            $io->success(\sprintf('Done: %d updated, %d unchanged.', $updated, $skipped));
        }

        return Command::SUCCESS;
    }
}
