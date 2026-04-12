<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 065f hotfix — Re-chain the entire audit_log HMAC after fixing
 * the canonical row serialization.
 *
 * The original backfill (Version2026041200100000) included `id` in the
 * canonical row. But AuditLogger::computeHmacChain() computes the HMAC
 * BEFORE em->flush(), when `id` is still 0. This caused a mismatch
 * between the HMAC computed at write time and the HMAC recomputed by
 * the verify-chain command.
 *
 * Fix: exclude `id` from the canonical row (in toCanonicalRow(),
 * verify command, AND this re-backfill). The `id` is auto-generated
 * and sequential — it adds no tamper-detection value.
 */
final class Version2026041200200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 065f hotfix — Re-chain audit_log HMAC without id in canonical row';
    }

    public function up(Schema $schema): void
    {
        // Handled in postUp
    }

    public function postUp(Schema $schema): void
    {
        $hexKey = $_ENV['AUDIT_HMAC_KEY'] ?? '';

        if (strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \RuntimeException('AUDIT_HMAC_KEY env var required (64 hex chars)');
        }
        $key = (string) hex2bin($hexKey);

        $batchSize = 500;
        $offset = 0;
        $prevHmac = '';
        $total = 0;

        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, event_type, actor_type, actor_id, resource_type, resource_id, '
                . 'action, outcome, details, ip_address, trace_id, created_at '
                . 'FROM audit_log ORDER BY id ASC LIMIT :limit OFFSET :offset',
                ['limit' => $batchSize, 'offset' => $offset],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $rowId = $row['id'];

                // Exclude `id` from canonical — matches toCanonicalRow() fix
                unset($row['id']);

                if (is_string($row['details'])) {
                    $row['details'] = json_decode($row['details'], true) ?? [];
                }

                ksort($row);
                $canonical = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $hmac = hash_hmac('sha256', $prevHmac . $canonical, $key, true);

                $this->connection->update(
                    'audit_log',
                    [
                        'prev_hmac' => $prevHmac === '' ? null : $prevHmac,
                        'row_hmac' => $hmac,
                    ],
                    ['id' => $rowId],
                    [
                        'prev_hmac' => $prevHmac === '' ? \PDO::PARAM_NULL : \PDO::PARAM_LOB,
                        'row_hmac' => \PDO::PARAM_LOB,
                    ],
                );

                $prevHmac = $hmac;
                $total++;
            }

            $offset += $batchSize;
        }

        $this->write(sprintf('  <info>Re-chained %d audit_log rows (id excluded from canonical)</info>', $total));
    }

    public function down(Schema $schema): void
    {
        // No-op: the previous chain is lost. The new chain is correct.
    }
}
