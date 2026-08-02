<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251026221309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create persona table and add persona_id FK to lkp_scam_type';
    }

    public function up(Schema $schema): void
    {
        // Create persona table
        $this->addSql('
            CREATE TABLE persona (
                persona_id SERIAL PRIMARY KEY,
                persona_code VARCHAR(32) UNIQUE NOT NULL,
                persona_label VARCHAR(128) NOT NULL,
                persona_tone VARCHAR(256) NOT NULL,
                system_prompt TEXT NOT NULL,
                created_by VARCHAR(16) NOT NULL DEFAULT \'manual\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN NOT NULL DEFAULT true
            )
        ');

        // Create indexes
        $this->addSql('CREATE INDEX idx_persona_code ON persona(persona_code)');
        $this->addSql('CREATE INDEX idx_persona_active ON persona(is_active)');

        // Add persona_id FK to lkp_scam_type
        $this->addSql('ALTER TABLE lkp_scam_type ADD COLUMN persona_id INTEGER');
        $this->addSql('ALTER TABLE lkp_scam_type ADD CONSTRAINT fk_scam_type_persona FOREIGN KEY (persona_id) REFERENCES persona(persona_id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_scam_type_persona ON lkp_scam_type(persona_id)');
    }

    public function down(Schema $schema): void
    {
        // Remove FK and column from lkp_scam_type
        $this->addSql('DROP INDEX IF EXISTS idx_scam_type_persona');
        $this->addSql('ALTER TABLE lkp_scam_type DROP CONSTRAINT IF EXISTS fk_scam_type_persona');
        $this->addSql('ALTER TABLE lkp_scam_type DROP COLUMN IF EXISTS persona_id');

        // Drop persona table
        $this->addSql('DROP INDEX IF EXISTS idx_persona_active');
        $this->addSql('DROP INDEX IF EXISTS idx_persona_code');
        $this->addSql('DROP TABLE IF EXISTS persona');
    }
}
