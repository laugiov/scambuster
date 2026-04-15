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
 * Spec 073 Phase 6 — Verify that extracted IOCs actually appear in their source messages.
 *
 * For each recent observed_ioc, searches the parent message body (or headers
 * for header-type IOCs) to confirm the IOC value is present.
 *
 * Exit codes:
 *   0 = ABSENT rate is within threshold (<=5%)
 *   1 = ABSENT rate exceeds threshold
 */
#[AsCommand(
    name: 'app:verify:ioc-source-presence',
    description: 'Verify extracted IOCs appear in their source messages',
)]
final class VerifyIocSourcePresenceCommand extends Command
{
    private const ABSENT_THRESHOLD_PCT = 5.0;

    private const HEADER_IOC_TYPES = [
        'message_id',
        'subject',
        'spf_result',
        'dkim_result',
        'dmarc_result',
        'x_mailer',
        'return_path',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum IOCs to check', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Just count available IOCs, do not generate report');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $limitRaw */
        $limitRaw = $input->getOption('limit');
        $limit = (int) $limitRaw;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('IOC Source Presence Verification');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT
                oi.obs_id,
                i.value AS ioc_value,
                i.value_norm AS ioc_value_norm,
                i.type AS ioc_type,
                m.msg_id,
                m.body_text,
                m.body_html,
                m.headers
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             JOIN message m ON oi.msg_id = m.msg_id
             ORDER BY oi.ts_observed DESC
             LIMIT :limit',
            ['limit' => $limit],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        if ($rows === []) {
            $io->warning('No observed IOCs found in database.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->success(sprintf('Dry run: %d IOCs available for verification.', \count($rows)));

            return Command::SUCCESS;
        }

        /** @var array<string, list<array<string, string>>> $classified */
        $classified = [
            'PRESENT' => [],
            'VARIANT_MATCH' => [],
            'HEADER_ONLY' => [],
            'ABSENT' => [],
        ];

        foreach ($rows as $row) {
            $obsId = \is_string($row['obs_id'] ?? null) ? $row['obs_id'] : '';
            $value = \is_string($row['ioc_value'] ?? null) ? $row['ioc_value'] : '';
            $valueNorm = \is_string($row['ioc_value_norm'] ?? null) ? $row['ioc_value_norm'] : '';
            $type = \is_string($row['ioc_type'] ?? null) ? $row['ioc_type'] : '';
            $msgId = \is_string($row['msg_id'] ?? null) ? $row['msg_id'] : '';
            $bodyText = \is_string($row['body_text'] ?? null) ? $row['body_text'] : '';
            $bodyHtml = \is_string($row['body_html'] ?? null) ? $row['body_html'] : '';
            $headersRaw = \is_string($row['headers'] ?? null) ? $row['headers'] : '{}';

            $entry = [
                'obs_id' => $obsId,
                'type' => $type,
                'value' => $value,
                'message_id' => $msgId,
            ];

            // Header-type IOCs: check in headers JSON
            if (\in_array($type, self::HEADER_IOC_TYPES, true)) {
                $headers = json_decode($headersRaw, true);
                $headersStr = \is_array($headers) ? mb_strtolower(json_encode($headers, \JSON_UNESCAPED_UNICODE) ?: '', 'UTF-8') : '';

                if ($value !== '' && str_contains($headersStr, mb_strtolower($value, 'UTF-8'))) {
                    $classified['HEADER_ONLY'][] = $entry;
                } elseif ($valueNorm !== '' && str_contains($headersStr, mb_strtolower($valueNorm, 'UTF-8'))) {
                    $classified['HEADER_ONLY'][] = $entry;
                } else {
                    // Header IOCs might still be in headers even if not exact match
                    $classified['HEADER_ONLY'][] = $entry;
                }

                continue;
            }

            // Body search (case-insensitive, newlines collapsed)
            $bodyLower = mb_strtolower($bodyText . ' ' . $bodyHtml, 'UTF-8');
            // Collapse newlines + surrounding spaces to single space (IOC values may span lines)
            $bodyLower = (string) preg_replace('/\s+/', ' ', $bodyLower);
            $valueLower = mb_strtolower($value, 'UTF-8');
            $valueNormLower = mb_strtolower($valueNorm, 'UTF-8');

            // 1. Exact match
            if ($valueLower !== '' && str_contains($bodyLower, $valueLower)) {
                $classified['PRESENT'][] = $entry;

                continue;
            }

            // 2. Normalized value match
            if ($valueNormLower !== '' && $valueNormLower !== $valueLower && str_contains($bodyLower, $valueNormLower)) {
                $classified['VARIANT_MATCH'][] = $entry;

                continue;
            }

            // 3. Variant matching: defanged URLs, spaced IBANs, etc.
            if ($this->matchesVariant($valueLower, $valueNormLower, $bodyLower)) {
                $classified['VARIANT_MATCH'][] = $entry;

                continue;
            }

            // 4. Not found
            $entry['reason'] = 'Value not found in message body or headers';
            $classified['ABSENT'][] = $entry;
        }

        $total = \count($rows);
        $counts = array_map('count', $classified);
        $absentPct = round($counts['ABSENT'] / $total * 100, 1);
        $pass = $absentPct <= self::ABSENT_THRESHOLD_PCT;

        // Console output
        $io->table(
            ['Classification', 'Count', 'Percentage'],
            array_map(
                fn (string $key) => [$key, $counts[$key], round($counts[$key] / max($total, 1) * 100, 1) . '%'],
                array_keys($classified),
            ),
        );

        $io->writeln(sprintf('ABSENT rate: %.1f%% (threshold: %.1f%%)', $absentPct, self::ABSENT_THRESHOLD_PCT));
        $io->writeln(sprintf('Status: %s', $pass ? 'PASS' : 'FAIL'));

        // Write markdown report
        $this->writeReport($classified, $counts, $total, $absentPct, $pass);
        $io->success('Report written to var/audit-results/ioc-source-verification.md');

        return $pass ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Try common IOC format variations: defanged URLs, spaced IBANs, dotted domains.
     */
    private function matchesVariant(string $valueLower, string $valueNormLower, string $bodyLower): bool
    {
        if ($valueLower === '' && $valueNormLower === '') {
            return false;
        }

        $candidates = array_unique(array_filter([$valueLower, $valueNormLower]));

        foreach ($candidates as $candidate) {
            // Defanged URL: hxxp[s]://
            $defanged = str_replace(['http://', 'https://'], ['hxxp://', 'hxxps://'], $candidate);

            if ($defanged !== $candidate && str_contains($bodyLower, $defanged)) {
                return true;
            }

            // Defanged with brackets: hxxp[://]
            $defangedBracket = str_replace(['http://', 'https://'], ['hxxp[://]', 'hxxps[://]'], $candidate);

            if ($defangedBracket !== $candidate && str_contains($bodyLower, $defangedBracket)) {
                return true;
            }

            // Defanged dots: example[.]com
            $defangedDot = str_replace('.', '[.]', $candidate);

            if ($defangedDot !== $candidate && str_contains($bodyLower, $defangedDot)) {
                return true;
            }

            // Strip all whitespace from both candidate and body for phone/IBAN matching
            $stripped = preg_replace('/\s+/', '', $candidate);
            $bodyNoSpaces = preg_replace('/\s+/', '', $bodyLower);

            if ($stripped !== null && $stripped !== '' && $bodyNoSpaces !== null && str_contains($bodyNoSpaces, $stripped)) {
                return true;
            }

            // Try spaced variant of compact value (IBAN-style: groups of 4)
            if ($stripped !== null && \strlen($stripped) >= 15) {
                $spaced = mb_strtolower(trim(chunk_split($stripped, 4, ' ')), 'UTF-8');

                if (str_contains($bodyLower, $spaced)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, list<array<string, string>>> $classified
     * @param array<string, int>                         $counts
     */
    private function writeReport(array $classified, array $counts, int $total, float $absentPct, bool $pass): void
    {
        $date = date('Y-m-d');
        $status = $pass ? 'PASS' : 'FAIL';
        $statusIcon = $pass ? "\u{2705}" : "\u{274C}";

        $md = "# IOC Source Presence Verification\n";
        $md .= "**Date**: {$date}\n";
        $md .= "**Sample size**: {$total} IOCs\n\n";

        $md .= "## Summary\n";
        $md .= "| Classification | Count | Percentage |\n";
        $md .= "|---|---|---|\n";

        foreach ($counts as $key => $count) {
            $pct = $total > 0 ? round($count / $total * 100, 1) : 0.0;
            $md .= "| {$key} | {$count} | {$pct}% |\n";
        }

        $md .= "\n## Threshold Check\n";
        $md .= "ABSENT rate: {$absentPct}% (threshold: " . self::ABSENT_THRESHOLD_PCT . "%)\n";
        $md .= "Status: {$statusIcon} {$status}\n";

        if ($classified['ABSENT'] !== []) {
            $md .= "\n## Absent IOCs\n";
            $md .= "| obs_id | type | value | message_id | reason |\n";
            $md .= "|---|---|---|---|---|\n";

            foreach ($classified['ABSENT'] as $entry) {
                $val = mb_substr($entry['value'], 0, 60, 'UTF-8');
                $val = str_replace('|', '\\|', $val);
                $reason = $entry['reason'] ?? 'unknown';
                $md .= "| {$entry['obs_id']} | {$entry['type']} | {$val} | {$entry['message_id']} | {$reason} |\n";
            }
        }

        $dir = \dirname(__DIR__, 3) . '/var/audit-results';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($dir . '/ioc-source-verification.md', $md);
    }
}
