<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the cosmetic `tenant_id` column from `app_users`.
 *
 * Background: the audit on 2026-04-11 identified the `tenant_id`
 * field as decoration only — `User::__construct` set it to a random
 * UUID with the comment `// dummy value for tests`, no repository
 * ever filtered by it, no Doctrine SQLFilter enforced tenant scope.
 *
 * The umbrella decision (per user instruction "fais au plus simple")
 * is to DROP the column entirely. A future commercial release that
 * requires real multi-tenancy will start from a clean schema (see
 * Phase 7.8 of `docs/06_roadmap.md`).
 *
 * Reversible: down() re-adds the column with a placeholder default
 * for emergency rollback during deploy.
 */
final class Version2026041112000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop cosmetic tenant_id column from app_users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_users DROP COLUMN tenant_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_users ADD COLUMN tenant_id UUID NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000'");
    }
}
