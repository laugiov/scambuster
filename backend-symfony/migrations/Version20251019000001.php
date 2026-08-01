<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Add unique index on observed_ioc and external_message_id field to message
 *
 * Changes:
 * 1. Add unique constraint on (msg_id, ioc.type, ioc.value_norm) in observed_ioc
 * 2. Add external_message_id field to message table for storing Gmail/Outlook message IDs
 */
final class Version20251019000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add IOC unique constraint and external_message_id field for email provider agnostic storage';
    }

    public function up(Schema $schema): void
    {
        // Add external_message_id column to message table (nullable for backwards compatibility)
        $this->addSql('ALTER TABLE message ADD COLUMN external_message_id VARCHAR(255) DEFAULT NULL');

        // Add index on external_message_id for fast lookups
        $this->addSql('CREATE INDEX idx_message_external_id ON message(external_message_id)');

        // Add unique constraint on observed_ioc: (msg_id, type, value_norm)
        // Using functional index on JSON fields for type and value_norm
        $this->addSql("
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_obs_msg_type_valnorm
            ON observed_ioc (
                msg_id,
                ((context->>'type')),
                ((context->>'value_norm'))
            )
        ");
    }

    public function down(Schema $schema): void
    {
        // Drop unique index
        $this->addSql('DROP INDEX IF EXISTS uniq_obs_msg_type_valnorm');

        // Drop external_message_id index and column
        $this->addSql('DROP INDEX IF EXISTS idx_message_external_id');
        $this->addSql('ALTER TABLE message DROP COLUMN IF EXISTS external_message_id');
    }
}
