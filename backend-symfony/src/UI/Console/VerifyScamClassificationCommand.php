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
 * Generate a spot-check report for manual scam type classification review.
 *
 * Samples conversations proportionally across statuses (open, closed, abandoned),
 * extracts the first inbound message body, anonymizes PII, and writes a
 * markdown report for human reviewers.
 *
 * Exit codes:
 *   0 = report generated (or no data)
 */
#[AsCommand(
    name: 'app:verify:classification',
    description: 'Generate spot-check report for scam type classification review',
)]
final class VerifyScamClassificationCommand extends Command
{
    private const BODY_TRUNCATE_LENGTH = 200;

    private const TARGET_STATUSES = ['open', 'closed', 'abandoned'];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sample', null, InputOption::VALUE_REQUIRED, 'Number of conversations to sample', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $sampleRaw */
        $sampleRaw = $input->getOption('sample');
        $sampleSize = (int) $sampleRaw;

        $io->title('Scam Classification Spot-Check');

        // Count conversations per status
        $statusCountsRaw = $this->connection->fetchAllKeyValue(
            'SELECT c.status, COUNT(*)
             FROM conversation c
             WHERE c.status IN (:open, :closed, :abandoned)
             GROUP BY c.status',
            ['open' => 'open', 'closed' => 'closed', 'abandoned' => 'abandoned'],
        );

        /** @var array<string, int> $statusCounts */
        $statusCounts = [];

        foreach ($statusCountsRaw as $key => $val) {
            /** @var int|string $val */
            $statusCounts[(string) $key] = (int) $val;
        }

        $totalAvailable = array_sum($statusCounts);

        if ($totalAvailable === 0) {
            $io->warning('No conversations found in database.');

            return Command::SUCCESS;
        }

        // Compute proportional allocation per status
        $perStatus = $this->computeAllocation($statusCounts, $sampleSize);

        $io->writeln(sprintf(
            'Sampling %d conversations: %s',
            $sampleSize,
            implode(', ', array_map(
                fn (string $s, int $n) => "{$s}={$n}",
                array_keys($perStatus),
                array_values($perStatus),
            )),
        ));

        /** @var list<array<string, string>> $entries */
        $entries = [];

        foreach ($perStatus as $status => $count) {
            if ($count <= 0) {
                continue;
            }

            $rows = $this->connection->fetchAllAssociative(
                'SELECT c.conv_id, c.status, st.code AS scam_type,
                        (SELECT m2.body_text
                         FROM message m2
                         JOIN lkp_direction d ON m2.direction = d.dir_id
                         WHERE m2.conv_id = c.conv_id AND d.code = :inbound
                         ORDER BY m2.ts_msg ASC
                         LIMIT 1) AS first_body
                 FROM conversation c
                 LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
                 WHERE c.status = :status
                 ORDER BY RANDOM()
                 LIMIT :lim',
                ['inbound' => 'in', 'status' => $status, 'lim' => $count],
                ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $convId = \is_string($row['conv_id'] ?? null) ? $row['conv_id'] : '';
                $convStatus = \is_string($row['status'] ?? null) ? $row['status'] : '';
                $scamType = \is_string($row['scam_type'] ?? null) ? $row['scam_type'] : 'UNKNOWN';
                $body = \is_string($row['first_body'] ?? null) ? $row['first_body'] : '';

                $body = mb_substr($body, 0, self::BODY_TRUNCATE_LENGTH, 'UTF-8');
                $body = $this->anonymize($body);

                $entries[] = [
                    'conv_id' => $convId,
                    'status' => $convStatus,
                    'scam_type' => $scamType,
                    'body' => $body,
                ];
            }
        }

        if ($entries === []) {
            $io->warning('No conversations with inbound messages found.');

            return Command::SUCCESS;
        }

        // Console summary
        $io->writeln(sprintf('Sampled %d conversations for review.', \count($entries)));

        // Write markdown report
        $this->writeReport($entries);
        $io->success('Report written to var/audit-results/classification-spot-check.md');

        return Command::SUCCESS;
    }

    /**
     * Compute proportional allocation of sample size across statuses.
     *
     * @param array<string, int> $statusCounts
     *
     * @return array<string, int>
     */
    private function computeAllocation(array $statusCounts, int $sampleSize): array
    {
        $total = array_sum($statusCounts);

        if ($total === 0) {
            return [];
        }

        /** @var array<string, int> $perStatus */
        $perStatus = [];
        $allocated = 0;

        foreach (self::TARGET_STATUSES as $status) {
            $count = $statusCounts[$status] ?? 0;

            if ($count === 0) {
                continue;
            }

            $share = (int) round($sampleSize * $count / $total);
            $share = min($share, $count); // Cannot sample more than available
            $perStatus[$status] = $share;
            $allocated += $share;
        }

        // Distribute remainder to the status with most available
        if ($allocated < $sampleSize && $perStatus !== []) {
            $remainder = $sampleSize - $allocated;

            arsort($perStatus);
            $firstKey = array_key_first($perStatus);
            $maxAvailable = $statusCounts[$firstKey] ?? 0;
            $perStatus[$firstKey] = min($perStatus[$firstKey] + $remainder, $maxAvailable);
        }

        return $perStatus;
    }

    /**
     * Anonymize PII: replace emails and phone numbers with placeholders.
     */
    private function anonymize(string $text): string
    {
        // Replace email addresses
        $text = (string) preg_replace(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            '[EMAIL]',
            $text,
        );

        // Replace phone numbers (international and local formats)
        $text = (string) preg_replace(
            '/(?:\+?\d{1,3}[\s\-]?)?\(?\d{2,4}\)?[\s\-]?\d{3,4}[\s\-]?\d{3,4}/',
            '[PHONE]',
            $text,
        );

        return $text;
    }

    /**
     * @param list<array<string, string>> $entries
     */
    private function writeReport(array $entries): void
    {
        $date = date('Y-m-d');
        $total = \count($entries);

        $md = "# Scam Classification Spot-Check\n";
        $md .= "**Date**: {$date}\n";
        $md .= "**Sample size**: {$total} conversations\n\n";

        $md .= "## Instructions\n";
        $md .= "Review each entry below. For each conversation, judge whether the assigned\n";
        $md .= "scam type matches the content of the first scammer message.\n";
        $md .= "Mark as: CORRECT | WRONG (suggest correct type) | AMBIGUOUS\n\n";

        $md .= "## Conversations\n";

        foreach ($entries as $i => $entry) {
            $num = $i + 1;
            $body = str_replace(["\r\n", "\r", "\n"], ' ', $entry['body']);
            $body = trim($body);

            if ($body === '') {
                $body = '(empty body)';
            }

            $md .= sprintf(
                "\n### %d. conv_id: %s | Status: %s | Type: %s\n",
                $num,
                mb_substr($entry['conv_id'], 0, 8, 'UTF-8'),
                $entry['status'],
                $entry['scam_type'],
            );
            $md .= "> {$body}\n\n";
            $md .= "**Your assessment**: [ ]\n";
        }

        $dir = \dirname(__DIR__, 3) . '/var/audit-results';

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        file_put_contents($dir . '/classification-spot-check.md', $md);
    }
}
