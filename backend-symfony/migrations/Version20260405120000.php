<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ioc_context table for structural and LLM contextual enrichment of IOCs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE ioc_context (
                id                    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                indicator_id          UUID NOT NULL,
                obs_id                UUID NOT NULL REFERENCES observed_ioc(obs_id) ON DELETE CASCADE,

                -- Structural context
                scam_type_code        VARCHAR(50),
                scam_type_attck       VARCHAR(32),
                scam_type_misp        VARCHAR(128),
                persona_code          VARCHAR(32),
                persona_label         VARCHAR(128),
                extraction_method     VARCHAR(20),
                revelation_turn       SMALLINT,
                total_turns           SMALLINT,
                revelation_turn_ratio NUMERIC(4,3),
                engagement_hours      NUMERIC(8,2),
                reward_value          NUMERIC(5,4),
                stimulus_msg_id       UUID,
                co_revealed_types     TEXT[],
                co_revealed_count     SMALLINT DEFAULT 0,
                campaign_id           UUID,

                -- LLM enrichment (Phase 043b, nullable until then)
                semantic_role         VARCHAR(30),
                stimulus_type         VARCHAR(30),
                urgency_score         NUMERIC(4,3),
                language_switch       BOOLEAN,
                hesitation_detected   BOOLEAN,
                context_excerpt       VARCHAR(300),
                enrichment_confidence NUMERIC(4,3),

                -- Metadata
                enrichment_status     VARCHAR(20) NOT NULL DEFAULT 'pending'
                    CHECK (enrichment_status IN ('pending','structural','enriched','failed','skipped')),
                enrichment_model      VARCHAR(50),
                enrichment_cost_usd   NUMERIC(10,8),
                computed_at           TIMESTAMPTZ,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW(),

                UNIQUE (obs_id)
            )
        ");

        $this->addSql('CREATE INDEX idx_ioc_context_indicator ON ioc_context (indicator_id)');
        $this->addSql('CREATE INDEX idx_ioc_context_status ON ioc_context (enrichment_status)');
        $this->addSql("CREATE INDEX idx_ioc_context_role ON ioc_context (semantic_role) WHERE semantic_role IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ioc_context');
    }
}
