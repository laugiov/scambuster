<?php

declare(strict_types=1);

namespace App\UI\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 061 — Sprint 2 — One-time historical cleanup of platform contamination.
 *
 * Removes IOCs that should never have been ingested:
 *   1. Every observed_ioc row that references an outgoing message (direction='out').
 *      These come from the LLM-generated reply bodies (555 phone numbers, our own
 *      headers) and from the unfiltered MigrateHeaderIocsCommand prior to spec 061.
 *   2. Every indicator that becomes orphan (no observed_ioc) after step 1.
 *   3. Every indicator whose value matches a configured honeypot email address,
 *      regardless of which messages observed it.
 *
 * Phases (each transactional):
 *   - Phase 1: resolve honeypot addresses (constructor injected from env var)
 *   - Phase 2: count rows about to be deleted (also done in --dry-run)
 *   - Phase 3: dump CSV audit (var/audit/061-cleanup-{timestamp}.csv) unless --no-csv
 *   - Phase 4: confirmation prompt unless --dry-run or --no-confirm
 *   - Phase 5: delete outgoing observations
 *   - Phase 6: delete orphan indicators
 *   - Phase 7: delete honeypot indicators (and their observations)
 *   - Phase 8: final report
 */
#[AsCommand(
    name: 'app:indicator:cleanup-platform-contamination',
    description: 'Spec 061: remove IOCs ingested from outgoing messages or matching honeypot addresses',
)]
final class CleanupPlatformContaminationCommand extends Command
{
    /** @var list<string> */
    private readonly array $defaultHoneypotAddresses;

    /**
     * @param list<string>|null $honeypotEmailAddresses Default honeypot addresses, normalised lowercase.
     *                                                  Overridable via --honeypot-address option.
     */
    public function __construct(
        private readonly Connection $conn,
        ?array $honeypotEmailAddresses = null,
        private readonly string $auditDir = '/app/var/audit',
    ) {
        parent::__construct();

        $normalised = [];

        foreach ($honeypotEmailAddresses ?? [] as $address) {
            $clean = strtolower(trim($address));

            if ($clean !== '') {
                $normalised[] = $clean;
            }
        }
        $this->defaultHoneypotAddresses = array_values(array_unique($normalised));
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report counts and write CSV but skip all DELETE statements'
            )
            ->addOption(
                'no-csv',
                null,
                InputOption::VALUE_NONE,
                'Do not write the audit CSV file'
            )
            ->addOption(
                'no-confirm',
                null,
                InputOption::VALUE_NONE,
                'Skip the interactive confirmation prompt (intended for tests / CI)'
            )
            ->addOption(
                'honeypot-address',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Override the configured honeypot addresses (repeatable)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $noCsv = (bool) $input->getOption('no-csv');
        $noConfirm = (bool) $input->getOption('no-confirm');

        /** @var list<string> $overrides */
        $overrides = $input->getOption('honeypot-address');
        $honeypotAddresses = empty($overrides)
            ? $this->defaultHoneypotAddresses
            : array_values(array_unique(array_map(fn ($a) => strtolower(trim($a)), $overrides)));

        $io->title('Spec 061 — Platform contamination cleanup');

        if ($dryRun) {
            $io->warning('DRY RUN — no DELETE will be executed');
        }

        $io->section('Phase 1 — Honeypot addresses');

        if ($honeypotAddresses === []) {
            $io->writeln('  (none configured — phase 7 will be a no-op)');
        } else {
            foreach ($honeypotAddresses as $a) {
                $io->writeln('  - ' . $a);
            }
        }

        // Phase 2 — count
        $io->section('Phase 2 — Counting affected rows');
        $counts = $this->countAffected($honeypotAddresses);
        $io->table(
            ['Category', 'Count'],
            [
                ['observed_ioc on outgoing messages (phase 5)', $counts['outgoing_observations']],
                ['orphan indicators after phase 5 (phase 6)', $counts['orphan_indicators_after_phase5']],
                ['honeypot email indicators (phase 7)', $counts['honeypot_indicators']],
                ['honeypot observations (phase 7 cascade)', $counts['honeypot_observations']],
            ]
        );

        $totalDeletes = $counts['outgoing_observations']
            + $counts['orphan_indicators_after_phase5']
            + $counts['honeypot_indicators']
            + $counts['honeypot_observations'];

        if ($totalDeletes === 0) {
            $io->success('Nothing to clean. Database is already free of platform contamination.');

            return Command::SUCCESS;
        }

        // Phase 3 — CSV audit dump
        if (!$noCsv) {
            $io->section('Phase 3 — Audit CSV');
            $csvPath = $this->writeAuditCsv($honeypotAddresses);
            $io->writeln('  Written: ' . $csvPath);
        }

        // Phase 4 — confirmation
        if (!$dryRun && !$noConfirm) {
            $io->section('Phase 4 — Confirmation');

            if (!$io->confirm(sprintf('Proceed with %d deletions?', $totalDeletes), false)) {
                $io->warning('Aborted by user.');

                return Command::SUCCESS;
            }
        }

        if ($dryRun) {
            $io->success('Dry run complete. Nothing was deleted.');

            return Command::SUCCESS;
        }

        // Phases 5–7 in a single transaction
        $io->section('Phases 5–7 — Deleting');
        $this->conn->beginTransaction();

        try {
            $deletedOutgoing = $this->deleteOutgoingObservations();
            $io->writeln(sprintf('  Phase 5: deleted %d outgoing observations', $deletedOutgoing));

            $deletedOrphans = $this->deleteOrphanIndicators();
            $io->writeln(sprintf('  Phase 6: deleted %d orphan indicators', $deletedOrphans));

            $deletedHoneypot = $this->deleteHoneypotIndicators($honeypotAddresses);
            $io->writeln(sprintf('  Phase 7: deleted %d honeypot indicators (with cascade observations)', $deletedHoneypot));

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $io->error('Cleanup transaction rolled back: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // Phase 8 — final report
        $io->section('Phase 8 — Final report');
        $remaining = $this->countAffected($honeypotAddresses);
        $allZero = $remaining['outgoing_observations'] === 0
            && $remaining['honeypot_indicators'] === 0;

        if ($allZero) {
            $io->success('Cleanup complete. Re-running this command is now a no-op.');
        } else {
            $io->warning(sprintf(
                'Cleanup partial: %d outgoing observations and %d honeypot indicators remain. Investigate.',
                $remaining['outgoing_observations'],
                $remaining['honeypot_indicators'],
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Run a COUNT(*) query and safely cast the result to int.
     *
     * @param array<int<0, max>|string, mixed>           $params
     * @param array<int<0, max>|string, int|string|null> $types
     */
    private function countQuery(string $sql, array $params = [], array $types = []): int
    {
        $value = $this->conn->fetchOne($sql, $params, $types);

        return \is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<string> $honeypotAddresses
     *
     * @return array{outgoing_observations: int, orphan_indicators_after_phase5: int, honeypot_indicators: int, honeypot_observations: int}
     */
    private function countAffected(array $honeypotAddresses): array
    {
        $outgoingObservations = $this->countQuery(
            "SELECT COUNT(*) FROM observed_ioc oi
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = 'out'"
        );

        // Indicators that would become orphan after phase 5 = indicators whose
        // ONLY observations are on outgoing messages.
        $orphanAfterPhase5 = $this->countQuery(
            "SELECT COUNT(*) FROM indicator i
             WHERE EXISTS (
                 SELECT 1 FROM observed_ioc oi
                 JOIN message m ON oi.msg_id = m.msg_id
                 JOIN lkp_direction d ON m.direction = d.dir_id
                 WHERE oi.indicator_id = i.indicator_id AND d.code = 'out'
             )
             AND NOT EXISTS (
                 SELECT 1 FROM observed_ioc oi
                 JOIN message m ON oi.msg_id = m.msg_id
                 JOIN lkp_direction d ON m.direction = d.dir_id
                 WHERE oi.indicator_id = i.indicator_id AND d.code = 'in'
             )"
        );

        $honeypotIndicators = 0;
        $honeypotObservations = 0;

        if ($honeypotAddresses !== []) {
            $honeypotIndicators = $this->countQuery(
                "SELECT COUNT(*) FROM indicator
                 WHERE type = 'email' AND LOWER(value_norm) IN (?)",
                [$honeypotAddresses],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );

            $honeypotObservations = $this->countQuery(
                "SELECT COUNT(*) FROM observed_ioc oi
                 JOIN indicator i ON oi.indicator_id = i.indicator_id
                 WHERE i.type = 'email' AND LOWER(i.value_norm) IN (?)",
                [$honeypotAddresses],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
        }

        return [
            'outgoing_observations' => $outgoingObservations,
            'orphan_indicators_after_phase5' => $orphanAfterPhase5,
            'honeypot_indicators' => $honeypotIndicators,
            'honeypot_observations' => $honeypotObservations,
        ];
    }

    /**
     * @param list<string> $honeypotAddresses
     */
    private function writeAuditCsv(array $honeypotAddresses): string
    {
        if (!is_dir($this->auditDir)) {
            mkdir($this->auditDir, 0o755, true);
        }

        $path = sprintf('%s/061-cleanup-%s.csv', $this->auditDir, date('Y-m-d_H-i-s'));
        $fh = fopen($path, 'w');

        if ($fh === false) {
            throw new \RuntimeException('Cannot write audit CSV at ' . $path);
        }

        fputcsv($fh, ['phase', 'indicator_id', 'type', 'value_norm', 'msg_id', 'direction']);

        // Phase 5 candidates
        $rows = $this->conn->fetchAllAssociative(
            "SELECT i.indicator_id, i.type, i.value_norm, oi.msg_id, d.code AS direction
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = 'out'"
        );

        foreach ($rows as $r) {
            fputcsv($fh, ['outgoing', $r['indicator_id'], $r['type'], $r['value_norm'], $r['msg_id'], $r['direction']]);
        }

        // Phase 7 candidates
        if ($honeypotAddresses !== []) {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT indicator_id, type, value_norm
                 FROM indicator
                 WHERE type = 'email' AND LOWER(value_norm) IN (?)",
                [$honeypotAddresses],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );

            foreach ($rows as $r) {
                fputcsv($fh, ['honeypot', $r['indicator_id'], $r['type'], $r['value_norm'], '', '']);
            }
        }

        fclose($fh);

        return $path;
    }

    private function deleteOutgoingObservations(): int
    {
        return (int) $this->conn->executeStatement(
            "DELETE FROM observed_ioc WHERE msg_id IN (
                 SELECT msg_id FROM message
                 WHERE direction = (SELECT dir_id FROM lkp_direction WHERE code = 'out')
             )"
        );
    }

    private function deleteOrphanIndicators(): int
    {
        return (int) $this->conn->executeStatement(
            'DELETE FROM indicator WHERE indicator_id NOT IN (SELECT indicator_id FROM observed_ioc)'
        );
    }

    /**
     * @param list<string> $honeypotAddresses
     */
    private function deleteHoneypotIndicators(array $honeypotAddresses): int
    {
        if ($honeypotAddresses === []) {
            return 0;
        }

        // Cascade observations first (FK has no ON DELETE CASCADE on indicator)
        $this->conn->executeStatement(
            "DELETE FROM observed_ioc WHERE indicator_id IN (
                 SELECT indicator_id FROM indicator
                 WHERE type = 'email' AND LOWER(value_norm) IN (?)
             )",
            [$honeypotAddresses],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );

        return (int) $this->conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'email' AND LOWER(value_norm) IN (?)",
            [$honeypotAddresses],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );
    }
}
