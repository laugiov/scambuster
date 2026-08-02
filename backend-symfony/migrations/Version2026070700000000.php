<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Harden refresh tokens at rest.
 *
 * - Hash the stored token: plaintext `token` VARCHAR(128) -> SHA-256 `token_hash` VARCHAR(64).
 *   Data-preserving: existing tokens are hashed in place with the SAME digest the application
 *   computes — verified `encode(sha256('abc'::bytea),'hex')` === PHP `hash('sha256','abc')` — so
 *   live sessions survive (a client keeps its raw token and it still matches after the switch).
 * - Add a per-login `family` lineage id used for refresh-token reuse detection (replaying a
 *   rotated token revokes the whole family). Existing rows each get a fresh family (lineage
 *   before this migration is unknown); new logins start proper families.
 */
final class Version2026070700000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Refresh-token hardening: hash at rest (token -> token_hash) + family lineage';
    }

    public function up(Schema $schema): void
    {
        // 1. Hash existing plaintext tokens in place (identical to PHP hash('sha256', $raw)).
        $this->addSql("UPDATE refreshtoken SET token = encode(sha256(token::bytea), 'hex')");
        // 2. A SHA-256 hex digest is exactly 64 chars.
        $this->addSql('ALTER TABLE refreshtoken ALTER COLUMN token TYPE VARCHAR(64)');
        // 3. Rename to reflect that it now holds a hash, not the token.
        $this->addSql('ALTER TABLE refreshtoken RENAME COLUMN token TO token_hash');
        // 4. Per-login family lineage: add nullable, backfill, then enforce NOT NULL + index.
        $this->addSql('ALTER TABLE refreshtoken ADD COLUMN family VARCHAR(36) DEFAULT NULL');
        $this->addSql('UPDATE refreshtoken SET family = gen_random_uuid()::text WHERE family IS NULL');
        $this->addSql('ALTER TABLE refreshtoken ALTER COLUMN family SET NOT NULL');
        $this->addSql('CREATE INDEX idx_refreshtoken_family ON refreshtoken (family)');
    }

    public function down(Schema $schema): void
    {
        // Structural reversal only — the original plaintext tokens cannot be recovered from hashes.
        $this->addSql('DROP INDEX IF EXISTS idx_refreshtoken_family');
        $this->addSql('ALTER TABLE refreshtoken DROP COLUMN family');
        $this->addSql('ALTER TABLE refreshtoken RENAME COLUMN token_hash TO token');
        $this->addSql('ALTER TABLE refreshtoken ALTER COLUMN token TYPE VARCHAR(128)');
    }
}
