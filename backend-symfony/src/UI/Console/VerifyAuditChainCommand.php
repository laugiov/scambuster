<?php

declare(strict_types=1);

namespace App\UI\Console;

use App\Application\Audit\AuditHmacChainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spec 065f — Verify the HMAC chain integrity of the audit_log table.
 *
 * Iterates all rows by id ASC, recomputes each row's expected HMAC,
 * and compares to the stored `row_hmac`. Reports mismatches with the
 * affected row ID.
 *
 * Exit codes:
 *   0 = chain is intact
 *   1 = at least one mismatch found (tamper detected)
 *
 * Designed to run daily via the scheduler (02:00 UTC).
 */
#[AsCommand(
    name: 'app:audit:verify-chain',
    description: 'Spec 065f — verify the HMAC chain of the audit_log table',
)]
final class VerifyAuditChainCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditHmacChainer $chainer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>[065f] Verifying audit_log HMAC chain...</info>');

        $offset = 0;
        $verified = 0;
        $mismatches = 0;
        $prevHmac = '';

        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, event_type, actor_type, actor_id, resource_type, resource_id, '
                . 'action, outcome, details, ip_address, trace_id, created_at, '
                . 'prev_hmac, row_hmac '
                . 'FROM audit_log ORDER BY id ASC LIMIT :limit OFFSET :offset',
                ['limit' => self::BATCH_SIZE, 'offset' => $offset],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                // Extract stored HMACs (PG BYTEA → PHP resource or string)
                $storedRowHmac = $this->toBin($row['row_hmac'] ?? null);

                if ($storedRowHmac === null || $storedRowHmac === '') {
                    // Row was inserted before the backfill or has a null hmac
                    $output->writeln(sprintf('<comment>[ID=%d] row_hmac is NULL — skipped</comment>', $row['id']));
                    $verified++;
                    continue;
                }

                // Build canonical row (same fields used by AuditHmacChainer)
                // `id` is excluded because AuditLogger computes the HMAC
                // before em->flush() when id is still 0 (auto-generated).
                $canonical = $row;
                unset($canonical['id'], $canonical['prev_hmac'], $canonical['row_hmac']);
                // details is JSON string from DB — decode for canonical serialization
                if (is_string($canonical['details'])) {
                    $canonical['details'] = json_decode($canonical['details'], true) ?? [];
                }

                $expectedHmac = $this->chainer->compute($prevHmac, $canonical);

                if ($expectedHmac !== $storedRowHmac) {
                    $output->writeln(sprintf(
                        '<error>[ID=%d] ROW_HMAC MISMATCH expected=%s actual=%s</error>',
                        $row['id'],
                        bin2hex($expectedHmac),
                        bin2hex($storedRowHmac),
                    ));
                    $mismatches++;
                }

                $prevHmac = $storedRowHmac;
                $verified++;
            }

            $offset += self::BATCH_SIZE;
        }

        $output->writeln(sprintf(
            '<info>[065f] Verified %d rows, %d mismatches</info>',
            $verified,
            $mismatches,
        ));

        return $mismatches === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function toBin(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            return stream_get_contents($value) ?: null;
        }

        return is_string($value) ? $value : null;
    }
}
