<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\LLM\SignatureStripper;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spec 080 §5 — one-shot migration command for replies queued in n8n
 * WF-REPLY-SEND-v1 at deploy time, before they go through SMTP.
 *
 * Scans recent outbound `message` rows (direction = 2) and re-applies
 * {@see SignatureStripper}. If the stripper would remove bytes, the
 * row's body_text is updated in place.
 *
 * Idempotent: re-running the command on already-stripped text produces
 * zero modifications.
 */
#[AsCommand(
    name: 'scambuster:strip-pending-signatures',
    description: 'Spec 080 — strip queued outbound replies whose body still contains a signature block',
)]
final class StripPendingSignaturesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SignatureStripper $stripper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Report what would change without writing to the database.',
        );
        $this->addOption(
            'since',
            null,
            InputOption::VALUE_REQUIRED,
            'PostgreSQL interval string limiting how far back to scan (default "1 hour"). Only outbound rows with ts_msg > NOW() - INTERVAL :since are considered.',
            '1 hour',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $sinceOpt = $input->getOption('since');
        $since = \is_string($sinceOpt) ? $sinceOpt : '1 hour';

        $io->section($dryRun ? 'DRY RUN — no rows will be modified' : 'LIVE RUN');
        $io->writeln("Scanning outbound messages with ts_msg > NOW() - INTERVAL '<info>{$since}</info>'");

        // JOIN on lkp_direction.code = 'out' rather than hardcoding the
        // numeric direction_id (which differs between dev/prod fixtures and
        // test fixtures — see SignatureStripper integration tests).
        $rows = $this->connection->executeQuery(
            "SELECT m.msg_id, m.body_text
             FROM message m
             INNER JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = 'out'
               AND m.body_text IS NOT NULL
               AND m.ts_msg > NOW() - (:since)::interval
             ORDER BY m.ts_msg DESC",
            ['since' => $since],
        )->fetchAllAssociative();

        $scanned = 0;
        $modified = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $scanned++;
            $msgIdRaw = $row['msg_id'] ?? null;
            $bodyRaw = $row['body_text'] ?? null;

            if (!\is_string($msgIdRaw) || !\is_string($bodyRaw)) {
                $skipped++;

                continue;
            }
            $msgId = $msgIdRaw;
            $body = $bodyRaw;

            $result = $this->stripper->strip($body, $msgId);

            if ($result->bytesRemoved === 0) {
                $skipped++;

                continue;
            }
            $modified++;

            $beforeTail = $this->lastChars($body, 80);
            $afterTail = $this->lastChars($result->textAfter, 80);

            $io->writeln(sprintf(
                "<info>%s</info> — bytes_removed=%d patterns=[%s]\n  before_tail: %s\n  after_tail:  %s",
                $msgId,
                $result->bytesRemoved,
                implode(', ', $result->matchedPatterns),
                $beforeTail,
                $afterTail,
            ));

            if (!$dryRun) {
                $this->connection->update(
                    'message',
                    ['body_text' => $result->textAfter],
                    ['msg_id' => $msgId],
                );
            }
        }

        $io->success(sprintf(
            'Scanned %d row(s), modified %d, skipped %d (already clean).',
            $scanned,
            $modified,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function lastChars(string $text, int $n): string
    {
        $printable = str_replace(["\n", "\r"], ['\\n', '\\r'], $text);

        return \strlen($printable) <= $n ? $printable : '…' . substr($printable, -$n);
    }
}
