<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spec 065f — Add HMAC chain columns to audit_log.
 *
 * Adds `prev_hmac` (BYTEA, nullable) and `row_hmac` (BYTEA, nullable)
 * columns, then backfills the HMAC chain for every existing row using
 * the AUDIT_HMAC_KEY env var.
 *
 * The backfill iterates all rows ordered by id ASC and computes
 * `row_hmac = HMAC-SHA256(key, prev_hmac || canonical_json(row))`.
 *
 * Note: the PostgreSQL REVOKE (preventing UPDATE/DELETE on audit_log
 * for a restricted role) is a post-deployment ops step documented in
 * docs/runbooks/audit-hmac-key-rotation.md, not embedded in this
 * migration, because dev/test DBs use the superuser and the role
 * creation would block the test suite.
 */
final class Version2026041200100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 065f — Add prev_hmac + row_hmac columns to audit_log + backfill HMAC chain';
    }

    public function up(Schema $schema): void
    {
        // 1. Validate the HMAC key
        $hexKey = $_ENV['AUDIT_HMAC_KEY'] ?? null;

        if (!is_string($hexKey) || strlen($hexKey) !== 64 || !ctype_xdigit($hexKey)) {
            throw new \RuntimeException(
                'Spec 065f migration requires AUDIT_HMAC_KEY env var (64 hex chars). '
                . 'Generate with: openssl rand -hex 32',
            );
        }
        $key = (string) hex2bin($hexKey);

        // 2. Add columns
        $this->addSql('ALTER TABLE audit_log ADD COLUMN prev_hmac BYTEA');
        $this->addSql('ALTER TABLE audit_log ADD COLUMN row_hmac BYTEA');

        // 3. Backfill the HMAC chain
        // We must execute the schema changes first so the columns exist,
        // then iterate and update.
        // Force execution of pending SQL before the PHP loop.
        // (Doctrine Migrations buffers addSql; we need the columns to exist NOW.)

        // The columns are added via addSql which is deferred. We handle the
        // backfill in postUp() instead, which runs after all SQL is flushed.
    }

    public function postUp(Schema $schema): void
    {
        $hexKey = $_ENV['AUDIT_HMAC_KEY'] ?? '';
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
                // Details is JSON — decode for canonical serialization
                if (is_string($row['details'])) {
                    $row['details'] = json_decode($row['details'], true) ?? [];
                }

                // Canonical serialization
                ksort($row);
                $canonical = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $hmac = hash_hmac('sha256', $prevHmac . $canonical, $key, true);

                $this->connection->update(
                    'audit_log',
                    [
                        'prev_hmac' => $prevHmac === '' ? null : $prevHmac,
                        'row_hmac' => $hmac,
                    ],
                    ['id' => $row['id']],
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

        $this->write(sprintf('  <info>HMAC chain backfilled for %d audit_log rows</info>', $total));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP COLUMN row_hmac');
        $this->addSql('ALTER TABLE audit_log DROP COLUMN prev_hmac');
    }
}
