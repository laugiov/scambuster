<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Create persona_performance_stats table for contextual multi-armed bandit
 *
 * Structure:
 * - PRIMARY KEY: (persona_id, scam_type_id)
 * - FKs: persona(persona_id), scam_type(scam_type_id)
 * - Stats: sessions_count, reward_sum, reward_avg
 */
final class Version20251121100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create persona_performance_stats table for adaptive scambaiting (contextual multi-armed bandit)';
    }

    public function up(Schema $schema): void
    {
        // Create table
        $this->addSql('
            CREATE TABLE persona_performance_stats (
                persona_id INTEGER NOT NULL,
                scam_type_id INTEGER NOT NULL,
                sessions_count INTEGER NOT NULL DEFAULT 0,
                reward_sum NUMERIC(10,4) NOT NULL DEFAULT 0.0000,
                reward_avg NUMERIC(5,4) NOT NULL DEFAULT 0.0000,
                last_updated TIMESTAMP NOT NULL DEFAULT NOW(),

                PRIMARY KEY (persona_id, scam_type_id),

                CONSTRAINT fk_persona_performance_persona
                    FOREIGN KEY (persona_id)
                    REFERENCES persona(persona_id)
                    ON DELETE CASCADE,

                CONSTRAINT fk_persona_performance_scam_type
                    FOREIGN KEY (scam_type_id)
                    REFERENCES lkp_scam_type(scam_type_id)
                    ON DELETE CASCADE,

                CONSTRAINT chk_sessions_count_positive
                    CHECK (sessions_count >= 0),

                CONSTRAINT chk_reward_avg_bounds
                    CHECK (reward_avg >= 0.0 AND reward_avg <= 1.0)
            )
        ');

        // Add comments
        $this->addSql("
            COMMENT ON TABLE persona_performance_stats IS 'Aggregated stats for contextual multi-armed bandit (1 bandit per scam_type)'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.persona_id IS 'Persona ID (FK to persona)'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.scam_type_id IS 'Scam type ID (FK to scam_type)'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.sessions_count IS 'Number of completed conversations with this (persona, scam_type)'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.reward_sum IS 'Cumulative sum of rewards (for moving average calculation)'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.reward_avg IS 'Moving average of rewards (0-1), incrementally updated'
        ");
        $this->addSql("
            COMMENT ON COLUMN persona_performance_stats.last_updated IS 'Timestamp of last update (for audit)'
        ");

        // Add indexes
        $this->addSql('
            CREATE INDEX idx_persona_perf_scam_type ON persona_performance_stats(scam_type_id)
        ');
        $this->addSql('
            CREATE INDEX idx_persona_perf_reward_desc ON persona_performance_stats(reward_avg DESC)
        ');
        $this->addSql('
            CREATE INDEX idx_persona_perf_updated ON persona_performance_stats(last_updated DESC)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS persona_performance_stats CASCADE');
    }
}
