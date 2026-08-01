<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Scambaiting\PersonaMirrorGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Batch-generate the Cognitive Mirror cache.
 *
 * Iterates every active (persona, scam type) pair, calls the LLM
 * via PersonaMirrorGenerator, and persists the result. Idempotent
 * by default (skips pairs that already have a row); --force
 * re-generates everything.
 *
 * Budget guard breaks the loop when cumulative cost exceeds the
 * threshold. ~$0.35 expected for 351 pairs at gpt-4o-mini pricing.
 */
#[AsCommand(
    name: 'app:persona:compute-mirrors',
    description: 'Generate the Cognitive Mirror cache (one LLM call per persona x scam type pair)',
)]
final class ComputePersonaMirrorsCommand extends Command
{
    public function __construct(
        private readonly PersonaMirrorGenerator $generator,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('persona', null, InputOption::VALUE_REQUIRED, 'Limit to this persona_code')
            ->addOption('scam-type', null, InputOption::VALUE_REQUIRED, 'Limit to this scam type code')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-generate pairs that already have a row')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Cumulative cost cap (USD)', '2.00')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print pairs that would be processed, do not call the LLM');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $personaOpt = $input->getOption('persona');
        $personaFilter = \is_string($personaOpt) ? $personaOpt : '';
        $scamTypeOpt = $input->getOption('scam-type');
        $scamTypeFilter = \is_string($scamTypeOpt) ? $scamTypeOpt : '';
        $force = (bool) $input->getOption('force');
        $budgetOpt = $input->getOption('budget-usd');
        $budgetUsd = is_numeric($budgetOpt) ? (float) $budgetOpt : 2.00;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Cognitive Mirror batch');
        $io->writeln(sprintf(
            '  persona filter: %s | scam-type filter: %s | force: %s | budget: $%.2f | dry-run: %s',
            $personaFilter !== '' ? $personaFilter : '(all active)',
            $scamTypeFilter !== '' ? $scamTypeFilter : '(all active)',
            $force ? 'yes' : 'no',
            $budgetUsd,
            $dryRun ? 'yes' : 'no',
        ));

        $pairs = $this->loadPairs($personaFilter, $scamTypeFilter);

        if ($pairs === []) {
            $io->warning('No pairs matched the filters.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  %d candidate pairs.', \count($pairs)));
        $io->newLine();

        $okCount = 0;
        $skipCount = 0;
        $errCount = 0;
        $cumulative = 0.0;

        foreach ($pairs as $i => $pair) {
            if ($cumulative >= $budgetUsd) {
                $io->warning(sprintf('Budget cap reached ($%.4f / $%.2f). Stopping.', $cumulative, $budgetUsd));

                break;
            }

            if (!$force && $this->generator->exists($pair['persona_id'], $pair['scam_type_id'])) {
                ++$skipCount;

                continue;
            }

            if ($dryRun) {
                $io->writeln(sprintf('  [%d/%d] would generate %s × %s', $i + 1, \count($pairs), $pair['persona_code'], $pair['scam_type_code']));
                ++$okCount;

                continue;
            }

            $result = $this->generator->generateForPair($pair['persona_id'], $pair['scam_type_id']);
            $cumulative += $result['cost_estimate_usd'];

            if ($result['success']) {
                $io->writeln(sprintf('  [%d/%d] %s × %s — OK (cumulative $%.4f)', $i + 1, \count($pairs), $pair['persona_code'], $pair['scam_type_code'], $cumulative));
                ++$okCount;
            } else {
                $io->writeln(sprintf('  [%d/%d] %s × %s — ERROR: %s', $i + 1, \count($pairs), $pair['persona_code'], $pair['scam_type_code'], $result['error'] ?? 'unknown'));
                ++$errCount;
            }
        }

        $io->newLine();
        $io->writeln(sprintf('ok=%d skipped=%d errors=%d total_cost=$%.4f', $okCount, $skipCount, $errCount, $cumulative));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{persona_id: int, persona_code: string, scam_type_id: int, scam_type_code: string}>
     */
    private function loadPairs(string $personaFilter, string $scamTypeFilter): array
    {
        $sql = 'SELECT p.persona_id, p.persona_code, st.scam_type_id, st.code AS scam_type_code'
            . ' FROM persona p CROSS JOIN lkp_scam_type st'
            . ' WHERE p.is_active = TRUE AND st.active = TRUE';
        $params = [];

        if ($personaFilter !== '') {
            $sql .= ' AND p.persona_code = :persona';
            $params['persona'] = $personaFilter;
        }

        if ($scamTypeFilter !== '') {
            $sql .= ' AND st.code = :scam_type';
            $params['scam_type'] = $scamTypeFilter;
        }

        $sql .= ' ORDER BY p.persona_code, st.code';

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'persona_id' => is_numeric($row['persona_id'] ?? null) ? (int) $row['persona_id'] : 0,
                'persona_code' => \is_string($row['persona_code'] ?? null) ? $row['persona_code'] : '',
                'scam_type_id' => is_numeric($row['scam_type_id'] ?? null) ? (int) $row['scam_type_id'] : 0,
                'scam_type_code' => \is_string($row['scam_type_code'] ?? null) ? $row['scam_type_code'] : '',
            ];
        }

        return $out;
    }
}
