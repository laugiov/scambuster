<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create indicator table (non-Doctrine managed, used by IocHandler via raw SQL).
 *
 * This table was previously created manually and was not part of Doctrine migrations.
 */
final class Version20260307190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create indicator table for IOC deduplication (raw SQL, not Doctrine-managed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS indicator (
                indicator_id UUID NOT NULL,
                type TEXT NOT NULL,
                value TEXT NOT NULL,
                value_norm TEXT NOT NULL,
                first_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_seen TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_enriched TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                occurrences INT NOT NULL DEFAULT 1,
                enrichment JSON DEFAULT NULL,
                score JSON DEFAULT NULL,
                tlp TEXT NOT NULL DEFAULT 'AMBER',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (indicator_id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_indicator_type_value ON indicator (type, value_norm)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_indicator_type ON indicator (type)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_indicator_first_seen ON indicator (first_seen)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP TABLE IF EXISTS indicator
        SQL);
    }
}
