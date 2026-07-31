<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: ScamType ManyToOne → ManyToMany with Persona
 *
 * Focused migration that ONLY changes ScamType-Persona relationship.
 * Does not touch other entities to avoid view/constraint conflicts.
 */
final class Version20251028015126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate ScamType-Persona relation from ManyToOne to ManyToMany (focused migration)';
    }

    public function up(Schema $schema): void
    {
        // 1. Create junction table scam_type_persona
        $this->addSql('
            CREATE TABLE scam_type_persona (
                scam_type_id INTEGER NOT NULL,
                persona_id INTEGER NOT NULL,
                PRIMARY KEY(scam_type_id, persona_id),
                CONSTRAINT fk_scam_type_persona_scam_type FOREIGN KEY (scam_type_id)
                    REFERENCES lkp_scam_type(scam_type_id) ON DELETE CASCADE,
                CONSTRAINT fk_scam_type_persona_persona FOREIGN KEY (persona_id)
                    REFERENCES persona(persona_id) ON DELETE CASCADE
            )
        ');

        $this->addSql('CREATE INDEX IDX_241C103A618DE68 ON scam_type_persona(scam_type_id)');
        $this->addSql('CREATE INDEX IDX_241C103F5F88DB9 ON scam_type_persona(persona_id)');

        // 2. Migrate existing data: copy (scam_type_id, persona_id) to junction table
        $this->addSql('
            INSERT INTO scam_type_persona (scam_type_id, persona_id)
            SELECT scam_type_id, persona_id
            FROM lkp_scam_type
            WHERE persona_id IS NOT NULL
        ');

        // 3. Drop old ManyToOne relationship
        $this->addSql('ALTER TABLE lkp_scam_type DROP CONSTRAINT IF EXISTS fk_lkp_scam_type_persona');
        $this->addSql('DROP INDEX IF EXISTS IDX_6DF8B1A5F5F88DB9');
        $this->addSql('ALTER TABLE lkp_scam_type DROP COLUMN persona_id');
    }

    public function down(Schema $schema): void
    {
        // 1. Re-add persona_id column to lkp_scam_type
        $this->addSql('ALTER TABLE lkp_scam_type ADD COLUMN persona_id INTEGER NULL');

        $this->addSql('
            ALTER TABLE lkp_scam_type
            ADD CONSTRAINT fk_lkp_scam_type_persona
            FOREIGN KEY (persona_id) REFERENCES persona(persona_id)
        ');

        $this->addSql('CREATE INDEX IDX_6DF8B1A5F5F88DB9 ON lkp_scam_type(persona_id)');

        // 2. Migrate data back: pick first persona from junction table for each scam_type
        $this->addSql('
            UPDATE lkp_scam_type st
            SET persona_id = (
                SELECT persona_id
                FROM scam_type_persona stp
                WHERE stp.scam_type_id = st.scam_type_id
                LIMIT 1
            )
        ');

        // 3. Drop junction table
        $this->addSql('DROP TABLE IF EXISTS scam_type_persona');
    }
}
