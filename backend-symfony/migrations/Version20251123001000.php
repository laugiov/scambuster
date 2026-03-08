<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Rename observed_ioc columns to match Doctrine entity mapping
 *
 * Fixes discrepancy between database schema and entity annotations:
 * - ioc_id → indicator_id
 * - context → context_observation
 *
 * Background:
 * Version20250518105021 created observed_ioc table with old column names.
 * The ObservedIoc entity was later updated to use clearer names but no
 * migration was created to rename the columns.
 */
final class Version20251123001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename observed_ioc columns: ioc_id → indicator_id, context → context_observation';
    }

    public function up(Schema $schema): void
    {
        // Rename columns to match Doctrine entity mapping
        $this->addSql('
            ALTER TABLE observed_ioc
            RENAME COLUMN ioc_id TO indicator_id
        ');

        $this->addSql('
            ALTER TABLE observed_ioc
            RENAME COLUMN context TO context_observation
        ');

        // Update column comments
        $this->addSql("
            COMMENT ON COLUMN observed_ioc.indicator_id IS 'UUID de l''indicateur IOC (FK logique vers table ioc)'
        ");

        $this->addSql("
            COMMENT ON COLUMN observed_ioc.context_observation IS 'Contexte JSON de l''observation (position dans message, métadonnées extraction)'
        ");
    }

    public function down(Schema $schema): void
    {
        // Revert column names
        $this->addSql('
            ALTER TABLE observed_ioc
            RENAME COLUMN indicator_id TO ioc_id
        ');

        $this->addSql('
            ALTER TABLE observed_ioc
            RENAME COLUMN context_observation TO context
        ');

        // Restore original comments
        $this->addSql("
            COMMENT ON COLUMN observed_ioc.ioc_id IS '(DC2Type:uuid)'
        ");

        $this->addSql("
            COMMENT ON COLUMN observed_ioc.context IS NULL
        ");
    }
}
