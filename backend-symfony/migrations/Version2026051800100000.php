<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Spec 085 §US3 — backfill headers.message-id from headers.provider_msg_id
 * for historical SMTP outbounds.
 *
 * Background: spec 050 migrated the send path from Gmail-API to Symfony
 * Mailer SMTP. The new path persisted the generated message-id only in
 * headers.provider_msg_id (with chevrons), forgetting to also write
 * headers.message-id (without chevrons) — which is what
 * ThreadResolverService::resolveConversation consults to link inbound
 * scammer replies to their parent outbound. Result: ~46% of outbounds
 * had headers.message-id NULL, and any scammer reply to them created
 * an orphan conversation.
 *
 * Spec 085 T02 fixes the forward case in sendEmail. This migration
 * (T04) backfills the historical rows so that:
 *  - the primary ThreadResolver lookup on headers.message-id succeeds
 *    immediately (faster than the spec 085 T03 fallback)
 *  - operator queries on conversation threading have a single canonical
 *    source of truth in headers.message-id
 *
 * The query is idempotent (re-running touches 0 rows thanks to the
 * `headers->>'message-id' IS NULL` guard). The `headers` column is
 * `json` (not `jsonb`) so we cast on read AND cast back on write.
 *
 * Irreversible content-wise: down() cannot know which rows were
 * touched (no tracking column), so it raises IrreversibleMigration.
 * Restoring previous state requires a backup.
 */
final class Version2026051800100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Spec 085 §US3 — backfill headers.message-id from headers.provider_msg_id for SMTP outbounds';
    }

    public function up(Schema $schema): void
    {
        // jsonb_set requires jsonb input; the `headers` column is `json`.
        // Cast on read, cast back on write. PostgreSQL 15+.
        //
        // trim(both '<>' from ...) strips leading + trailing chevrons
        // but preserves any interior characters. Matches the convention
        // sendEmail uses post-spec-085-T02.
        //
        // The WHERE clause guards idempotency: rows already populated
        // are NOT overwritten. Rows without a source provider_msg_id
        // are skipped (they remain orphans — no data to recover).
        $this->addSql(<<<'SQL'
            UPDATE message
            SET headers = jsonb_set(
                headers::jsonb,
                '{message-id}',
                to_jsonb(trim(both '<>' from (headers->>'provider_msg_id'))),
                true
            )::json
            WHERE direction = 2
              AND headers->>'message-id' IS NULL
              AND headers->>'provider_msg_id' IS NOT NULL
              AND headers->>'provider_msg_id' != ''
        SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Spec 085 §US3 — backfilled headers.message-id values cannot be selectively '
            . 'reverted (no tracking column distinguishes them from pre-existing ones). '
            . 'Restore from backup if a rollback is genuinely needed.',
        );
    }
}
