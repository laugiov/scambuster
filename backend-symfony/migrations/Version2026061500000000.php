<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The persona_scam_mirror table.
 *
 * Cache for the LLM-generated cognitive mirror text per (persona,
 * scam type) pair. One row per pair, populated by the
 * app:persona:compute-mirrors CLI command. Read-only from the
 * frontend.
 */
final class Version2026061500000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persona_scam_mirror table for the cognitive mirror cache';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE persona_scam_mirror (
                persona_id INTEGER NOT NULL,
                scam_type_id INTEGER NOT NULL,
                hunted_victim_profile TEXT NOT NULL,
                cognitive_lever VARCHAR(255) NOT NULL,
                mirror_explanation TEXT NOT NULL,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                generated_by_model VARCHAR(64) NOT NULL,
                prompt_version VARCHAR(16) NOT NULL,
                PRIMARY KEY (persona_id, scam_type_id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_persona_scam_mirror_persona ON persona_scam_mirror (persona_id)');
        $this->addSql('CREATE INDEX idx_persona_scam_mirror_scam_type ON persona_scam_mirror (scam_type_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE persona_scam_mirror
            ADD CONSTRAINT fk_persona_scam_mirror_persona
            FOREIGN KEY (persona_id) REFERENCES persona (persona_id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE persona_scam_mirror
            ADD CONSTRAINT fk_persona_scam_mirror_scam_type
            FOREIGN KEY (scam_type_id) REFERENCES lkp_scam_type (scam_type_id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE persona_scam_mirror IS 'LLM-generated cognitive mirror text per persona x scam type pair'
        SQL);
        $this->addSql("COMMENT ON COLUMN persona_scam_mirror.hunted_victim_profile IS 'Victim profile this scam type preys on (1 sentence)'");
        $this->addSql("COMMENT ON COLUMN persona_scam_mirror.cognitive_lever IS 'Primary emotional manipulation mechanism'");
        $this->addSql("COMMENT ON COLUMN persona_scam_mirror.mirror_explanation IS 'Why this persona matches the hunted target (1-2 sentences)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS persona_scam_mirror');
    }
}
