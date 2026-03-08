<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250510093411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renames the `user` table to `app_users` (creates if first install).';
    }

    public function up(Schema $schema): void
    {
        // 1.  Create the new table if it does not already exist.
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS app_users (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_C2502824E7927C74 ON app_users (email)
        SQL);
        $this->addSql("COMMENT ON COLUMN app_users.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN app_users.tenant_id IS '(DC2Type:uuid)'");

        // 2.  Drop the old table **if** it still exists.
        $this->addSql('DROP TABLE IF EXISTS "user"');
    }

    public function down(Schema $schema): void
    {
        // symmetric rollback
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS "user" (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                roles JSON NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_8d93d649e7927c74 ON "user" (email)
        SQL);
        $this->addSql("COMMENT ON COLUMN \"user\".id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN \"user\".tenant_id IS '(DC2Type:uuid)'");

        $this->addSql('DROP TABLE IF EXISTS app_users');
    }
}
