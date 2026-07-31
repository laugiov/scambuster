<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the prompt_override table backing operator-managed prompt customization
 * through the admin UI. Resolved by PromptProvider ahead of the on-disk file and the
 * shipped default (DB -> file -> default), only for enabled rows.
 *
 * Purely additive: creates one new table + its unique index. It touches no existing
 * table and removes no data, so it is safe to apply to a populated production database.
 */
final class Version2026072800000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create prompt_override table for operator prompt customization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prompt_override (id SERIAL NOT NULL, prompt_key VARCHAR(64) NOT NULL, body TEXT NOT NULL, enabled BOOLEAN DEFAULT true NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_prompt_override_key ON prompt_override (prompt_key)');
        $this->addSql("COMMENT ON COLUMN prompt_override.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE prompt_override');
    }
}
