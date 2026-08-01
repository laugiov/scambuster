<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The threat_actor_psych_profile table.
 *
 * Durable psychological + behavioural fingerprint per threat-actor cluster,
 * generated offline by the app:actor:compute-psych-profiles CLI command
 * (LLM over the cluster's inbound messages + persisted ioc_context signals).
 * One row per cluster. Read-only from the frontend, STIX export and API.
 */
final class Version2026070600000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add threat_actor_psych_profile table (per-cluster actor psychological fingerprint)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE threat_actor_psych_profile (
                cluster_id UUID NOT NULL,
                dominant_lever VARCHAR(32) NOT NULL,
                secondary_levers TEXT[] NOT NULL DEFAULT '{}',
                behavioural_summary TEXT NOT NULL,
                escalation_pattern VARCHAR(32) NOT NULL,
                victim_targeting TEXT NOT NULL,
                dominant_stimulus VARCHAR(64) DEFAULT NULL,
                avg_urgency DOUBLE PRECISION NOT NULL DEFAULT 0,
                hesitation_events INTEGER NOT NULL DEFAULT 0,
                language_switches INTEGER NOT NULL DEFAULT 0,
                conversation_count INTEGER NOT NULL DEFAULT 0,
                message_count INTEGER NOT NULL DEFAULT 0,
                generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                generated_by_model VARCHAR(64) NOT NULL,
                prompt_version VARCHAR(16) NOT NULL,
                PRIMARY KEY (cluster_id)
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE threat_actor_psych_profile
            ADD CONSTRAINT fk_actor_psych_profile_cluster
            FOREIGN KEY (cluster_id) REFERENCES threat_actor_cluster (cluster_id) ON DELETE CASCADE
        SQL);

        $this->addSql(<<<'SQL'
            COMMENT ON TABLE threat_actor_psych_profile IS 'Per-cluster actor psychological + behavioural fingerprint (offline LLM + ioc_context aggregate)'
        SQL);
        $this->addSql("COMMENT ON COLUMN threat_actor_psych_profile.dominant_lever IS 'Dominant Cialdini influence principle (RULE #7 vocabulary)'");
        $this->addSql("COMMENT ON COLUMN threat_actor_psych_profile.escalation_pattern IS 'How pressure evolves across turns (e.g. rapid/gradual/stable/erratic)'");
        $this->addSql("COMMENT ON COLUMN threat_actor_psych_profile.dominant_stimulus IS 'Most frequent ioc_context stimulus_type for this actor'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS threat_actor_psych_profile');
    }
}
