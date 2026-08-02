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
 * One-time historical cleanup of platform contamination.
 *
 * Removes IOCs that should never have been ingested:
 *   1. Every observed_ioc row that references an outgoing message (direction='out').
 *      These come from the LLM-generated reply bodies (555 phone numbers, our own
 *      headers) and from the unfiltered MigrateHeaderIocsCommand prior to the
 *      outgoing-message guard.
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
 *                Covers EMAIL, plus DOMAIN + URL whose value points back at
 *                the honeypot infrastructure.
 *   - Phase 8: final report
 */
#[AsCommand(
    name: 'app:indicator:cleanup-platform-contamination',
    description: 'Remove IOCs ingested from outgoing messages or matching honeypot addresses',
)]
final class CleanupPlatformContaminationCommand extends Command
{
    /** @var list<string> */
    private readonly array $defaultHoneypotAddresses;

    /** @var list<string> */
    private readonly array $defaultHoneypotDomains;

    /**
     * @param list<string>|null $honeypotEmailAddresses Default honeypot addresses, normalised lowercase.
     *                                                  Overridable via --honeypot-address option.
     * @param list<string>|null $honeypotDomains        Explicit OWNED honeypot domains
     *                                                  (NOT derived from emails — see services.yaml).
     *                                                  Overridable via --honeypot-domain option.
     */
    public function __construct(
        private readonly Connection $conn,
        ?array $honeypotEmailAddresses = null,
        private readonly string $auditDir = '/app/var/audit',
        ?array $honeypotDomains = null,
    ) {
        parent::__construct();

        $normalisedAddrs = [];

        foreach ($honeypotEmailAddresses ?? [] as $address) {
            $clean = strtolower(trim($address));

            if ($clean !== '') {
                $normalisedAddrs[] = $clean;
            }
        }
        $this->defaultHoneypotAddresses = array_values(array_unique($normalisedAddrs));

        $normalisedDomains = [];

        foreach ($honeypotDomains ?? [] as $domain) {
            $clean = strtolower(trim($domain));

            if ($clean !== '') {
                if (str_starts_with($clean, 'www.')) {
                    $clean = substr($clean, 4);
                }
                $normalisedDomains[] = $clean;
            }
        }
        $this->defaultHoneypotDomains = array_values(array_unique($normalisedDomains));
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
            )
            ->addOption(
                'honeypot-domain',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Override the configured honeypot OWNED domains (repeatable)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $noCsv = (bool) $input->getOption('no-csv');
        $noConfirm = (bool) $input->getOption('no-confirm');

        /** @var list<string> $addrOverrides */
        $addrOverrides = $input->getOption('honeypot-address');
        $honeypotAddresses = empty($addrOverrides)
            ? $this->defaultHoneypotAddresses
            : array_values(array_unique(array_map(fn ($a) => strtolower(trim($a)), $addrOverrides)));

        /** @var list<string> $domOverrides */
        $domOverrides = $input->getOption('honeypot-domain');
        $honeypotDomains = empty($domOverrides)
            ? $this->defaultHoneypotDomains
            : array_values(array_unique(array_map(static function (string $d): string {
                $clean = strtolower(trim($d));

                return str_starts_with($clean, 'www.') ? substr($clean, 4) : $clean;
            }, $domOverrides)));

        $io->title('Platform contamination cleanup');

        if ($dryRun) {
            $io->warning('DRY RUN — no DELETE will be executed');
        }

        $io->section('Phase 1a — Honeypot addresses');

        if ($honeypotAddresses === []) {
            $io->writeln('  (none configured)');
        } else {
            foreach ($honeypotAddresses as $a) {
                $io->writeln('  - ' . $a);
            }
        }

        $io->section('Phase 1b — Honeypot domains (owned only)');

        if ($honeypotDomains === []) {
            $io->writeln('  (none configured — domain/url filters and email-by-domain match are no-ops)');
        } else {
            foreach ($honeypotDomains as $d) {
                $io->writeln('  - ' . $d);
            }
        }

        // Phase 2 — count
        $io->section('Phase 2 — Counting affected rows');
        $counts = $this->countAffected($honeypotAddresses, $honeypotDomains);
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
            $csvPath = $this->writeAuditCsv($honeypotAddresses, $honeypotDomains);
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

            $deletedHoneypot = $this->deleteHoneypotIndicators($honeypotAddresses, $honeypotDomains);
            $io->writeln(sprintf('  Phase 7: deleted %d honeypot indicators (with cascade observations)', $deletedHoneypot));

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $io->error('Cleanup transaction rolled back: ' . $e->getMessage());

            return Command::FAILURE;
        }

        // Phase 8 — final report
        $io->section('Phase 8 — Final report');
        $remaining = $this->countAffected($honeypotAddresses, $honeypotDomains);
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
     * @param list<string> $honeypotDomains
     *
     * @return array{outgoing_observations: int, orphan_indicators_after_phase5: int, honeypot_indicators: int, honeypot_observations: int}
     */
    private function countAffected(array $honeypotAddresses, array $honeypotDomains = []): array
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

        if ($honeypotAddresses !== [] || $honeypotDomains !== []) {
            $honeypotIndicatorIds = $this->findHoneypotIndicatorIds($honeypotAddresses, $honeypotDomains);

            if ($honeypotIndicatorIds !== []) {
                $honeypotIndicators = \count($honeypotIndicatorIds);
                $honeypotObservations = $this->countQuery(
                    'SELECT COUNT(*) FROM observed_ioc WHERE indicator_id IN (?)',
                    [$honeypotIndicatorIds],
                    [\Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
        }

        return [
            'outgoing_observations' => $outgoingObservations,
            'orphan_indicators_after_phase5' => $orphanAfterPhase5,
            'honeypot_indicators' => $honeypotIndicators,
            'honeypot_observations' => $honeypotObservations,
        ];
    }

    /**
     * Un-defang an indicator value before matching. The
     * IocCategorizer stores value_norm in defanged form (e.g.
     * acme.example → acme[.]com) so the SQL `IN (?)` against the bare
     * domain list misses. Symmetric with IocUpsertService::unDefang().
     */
    private function unDefang(string $value): string
    {
        return str_replace(['[.]', '[/]', '[://]', '[:]'], ['.', '/', '://', ':'], $value);
    }

    /**
     * Resolve the set of indicator IDs that point back at the
     * honeypot infrastructure. Covers three IOC types via un-defanged
     * value_norm comparison:
     *
     *   - email  : exact match against $addresses OR domain-part match
     *              against $domains
     *   - domain : exact match against $domains (after stripping `www.`)
     *   - url    : parse_url(host), strip `www.`, match against $domains
     *
     * @param list<string> $addresses
     * @param list<string> $domains
     *
     * @return list<string>
     */
    private function findHoneypotIndicatorIds(array $addresses, array $domains): array
    {
        $ids = [];
        $addressesSet = array_fill_keys($addresses, true);

        if ($addresses !== []) {
            $emailRows = $this->conn->fetchAllAssociative(
                "SELECT indicator_id, value_norm FROM indicator WHERE type = 'email'",
            );

            foreach ($emailRows as $r) {
                $idVal = $r['indicator_id'] ?? null;
                $valueNorm = $r['value_norm'] ?? null;

                if (!\is_string($idVal) || !\is_string($valueNorm)) {
                    continue;
                }

                if (isset($addressesSet[strtolower($this->unDefang($valueNorm))])) {
                    $ids[$idVal] = true;
                }
            }
        }

        if ($domains !== []) {
            $domainsSet = array_fill_keys($domains, true);

            // EMAIL by domain part — catches persona aliases under a honeypot
            // domain that aren't enumerated in the addresses list.
            $emailRows = $this->conn->fetchAllAssociative(
                "SELECT indicator_id, value_norm FROM indicator WHERE type = 'email'",
            );

            foreach ($emailRows as $r) {
                $idVal = $r['indicator_id'] ?? null;
                $valueNorm = $r['value_norm'] ?? null;

                if (!\is_string($idVal) || !\is_string($valueNorm)) {
                    continue;
                }
                $needle = strtolower($this->unDefang($valueNorm));
                $atPos = strrpos($needle, '@');

                if ($atPos === false || $atPos >= strlen($needle) - 1) {
                    continue;
                }

                if (isset($domainsSet[substr($needle, $atPos + 1)])) {
                    $ids[$idVal] = true;
                }
            }

            // DOMAIN — exact match on un-defanged value_norm (handles www. prefix)
            $domainRows = $this->conn->fetchAllAssociative(
                "SELECT indicator_id, value_norm FROM indicator WHERE type = 'domain'",
            );

            foreach ($domainRows as $r) {
                $idVal = $r['indicator_id'] ?? null;
                $valueNorm = $r['value_norm'] ?? null;

                if (!\is_string($idVal) || !\is_string($valueNorm)) {
                    continue;
                }
                $clean = strtolower($this->unDefang($valueNorm));

                if (str_starts_with($clean, 'www.')) {
                    $clean = substr($clean, 4);
                }

                if (isset($domainsSet[$clean])) {
                    $ids[$idVal] = true;
                }
            }

            // URL — parse_url host on un-defanged value_norm
            $urlRows = $this->conn->fetchAllAssociative(
                "SELECT indicator_id, value_norm FROM indicator WHERE type = 'url'",
            );

            foreach ($urlRows as $r) {
                $idVal = $r['indicator_id'] ?? null;
                $valueNorm = $r['value_norm'] ?? null;

                if (!\is_string($idVal) || !\is_string($valueNorm)) {
                    continue;
                }
                $clean = strtolower($this->unDefang($valueNorm));

                // Scheme-less URLs (e.g. `www.example.com/x`) → parse_url
                // returns no host. Prefix `https://` so parse_url can find
                // the host. This is intent-preserving — we only use the
                // parsed host to compare against honeypotDomains.
                if (!preg_match('#^[a-z][a-z0-9+\-.]*://#', $clean)) {
                    $clean = 'https://' . $clean;
                }
                $host = parse_url($clean, PHP_URL_HOST);

                if (!\is_string($host) || $host === '') {
                    continue;
                }

                if (str_starts_with($host, 'www.')) {
                    $host = substr($host, 4);
                }

                if (isset($domainsSet[$host])) {
                    $ids[$idVal] = true;
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<string> $honeypotAddresses
     * @param list<string> $honeypotDomains
     */
    private function writeAuditCsv(array $honeypotAddresses, array $honeypotDomains = []): string
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
            /** @var array<int, string> $csvRow */
            $csvRow = ['outgoing', $r['indicator_id'] ?? '', $r['type'] ?? '', $r['value_norm'] ?? '', $r['msg_id'] ?? '', $r['direction'] ?? ''];
            fputcsv($fh, $csvRow);
        }

        // Phase 7 candidates — email + domain + url
        if ($honeypotAddresses !== [] || $honeypotDomains !== []) {
            $ids = $this->findHoneypotIndicatorIds($honeypotAddresses, $honeypotDomains);

            if ($ids !== []) {
                $rows = $this->conn->fetchAllAssociative(
                    'SELECT indicator_id, type, value_norm FROM indicator WHERE indicator_id IN (?)',
                    [$ids],
                    [\Doctrine\DBAL\ArrayParameterType::STRING],
                );

                foreach ($rows as $r) {
                    /** @var array<int, string> $honeypotRow */
                    $honeypotRow = ['honeypot', $r['indicator_id'] ?? '', $r['type'] ?? '', $r['value_norm'] ?? '', '', ''];
                    fputcsv($fh, $honeypotRow);
                }
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
     * Delete every indicator (email, domain, url)
     * whose value points back at the honeypot, and cascade its observed_ioc
     * rows first (no ON DELETE CASCADE on the FK).
     *
     * @param list<string> $honeypotAddresses
     * @param list<string> $honeypotDomains
     */
    private function deleteHoneypotIndicators(array $honeypotAddresses, array $honeypotDomains = []): int
    {
        if ($honeypotAddresses === [] && $honeypotDomains === []) {
            return 0;
        }

        $ids = $this->findHoneypotIndicatorIds($honeypotAddresses, $honeypotDomains);

        if ($ids === []) {
            return 0;
        }

        $this->conn->executeStatement(
            'DELETE FROM observed_ioc WHERE indicator_id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );

        return (int) $this->conn->executeStatement(
            'DELETE FROM indicator WHERE indicator_id IN (?)',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::STRING],
        );
    }
}
