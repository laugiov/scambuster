<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Campaign Radar Phase 1 : Domain Layer Migration
 * Crée les tables campaign, campaign_rule, message_campaign, actor_profile + vues SQL
 */
final class Version20251021140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Campaign Radar tables (campaign, campaign_rule, message_campaign, actor_profile) + views';
    }

    public function up(Schema $schema): void
    {
        // Extension pg_trgm pour recherche texte
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Table campaign
        $this->addSql('
            CREATE TABLE campaign (
                campaign_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                first_seen TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                status VARCHAR(20) NOT NULL CHECK (status IN (\'shadow\', \'promoted\', \'archived\')),
                actor_guess TEXT NULL,
                tlp VARCHAR(20) NOT NULL DEFAULT \'TLP:AMBER\',
                severity SMALLINT NOT NULL DEFAULT 2 CHECK (severity BETWEEN 1 AND 5),
                dsl_hash VARCHAR(64) NOT NULL,
                created_by TEXT NOT NULL,
                notes TEXT NULL,
                profile_yaml TEXT NULL,
                centroid_simhash VARCHAR(32) NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('COMMENT ON COLUMN campaign.profile_yaml IS \'YAML profile généré par LLM (CampaignProfiler)\'');
        $this->addSql('COMMENT ON COLUMN campaign.centroid_simhash IS \'Simhash MD5 centroid pour clustering similarity\'');

        $this->addSql('CREATE INDEX idx_campaign_status ON campaign(status)');
        $this->addSql('CREATE INDEX idx_campaign_first_seen ON campaign(first_seen DESC)');
        $this->addSql('CREATE INDEX idx_campaign_dsl_hash ON campaign(dsl_hash)');

        // Table campaign_rule
        $this->addSql('
            CREATE TABLE campaign_rule (
                rule_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                campaign_id UUID NOT NULL REFERENCES campaign(campaign_id) ON DELETE CASCADE,
                version INT NOT NULL DEFAULT 1,
                dsl TEXT NOT NULL,
                compiled_sql TEXT NULL,
                ppv NUMERIC(5,4) NOT NULL DEFAULT 0.0,
                hits_total INT NOT NULL DEFAULT 0,
                hits_true_pos INT NOT NULL DEFAULT 0,
                hits_false_pos INT NOT NULL DEFAULT 0,
                lead_time_sec INT NULL,
                promoted_at TIMESTAMPTZ NULL,
                enabled BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('CREATE INDEX idx_campaign_rule_campaign ON campaign_rule(campaign_id)');
        $this->addSql('CREATE INDEX idx_campaign_rule_enabled ON campaign_rule(enabled) WHERE enabled = TRUE');
        $this->addSql('CREATE INDEX idx_campaign_rule_ppv ON campaign_rule(ppv DESC) WHERE enabled = TRUE');

        // Table message_campaign (association)
        $this->addSql('
            CREATE TABLE message_campaign (
                msg_id UUID NOT NULL REFERENCES message(msg_id) ON DELETE CASCADE,
                campaign_id UUID NOT NULL REFERENCES campaign(campaign_id) ON DELETE CASCADE,
                confidence NUMERIC(5,4) NOT NULL CHECK (confidence BETWEEN 0 AND 1),
                detected_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                detected_by VARCHAR(50) NOT NULL,
                features JSONB NULL,
                is_true_positive BOOLEAN NULL,
                PRIMARY KEY (msg_id, campaign_id)
            )
        ');

        $this->addSql('COMMENT ON COLUMN message_campaign.features IS \'Features clustering (text/infra/style)\'');
        $this->addSql('COMMENT ON COLUMN message_campaign.is_true_positive IS \'True=TP, False=FP, Null=non validé\'');

        $this->addSql('CREATE INDEX idx_message_campaign_campaign ON message_campaign(campaign_id)');
        $this->addSql('CREATE INDEX idx_message_campaign_detected ON message_campaign(detected_at DESC)');
        $this->addSql('CREATE INDEX idx_message_campaign_features ON message_campaign USING gin(features)');

        // Table actor_profile
        $this->addSql('
            CREATE TABLE actor_profile (
                actor_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                style_dna JSONB NOT NULL,
                infra_dna JSONB NOT NULL,
                campaigns TEXT NOT NULL DEFAULT \'\',
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        // Vue pour metrics : shadow hits par campagne
        $this->addSql('
            CREATE VIEW v_campaign_shadow_hits AS
            SELECT
                cr.rule_id,
                cr.campaign_id,
                cr.version,
                cr.ppv,
                cr.hits_total,
                cr.hits_true_pos,
                cr.hits_false_pos,
                cr.lead_time_sec,
                COUNT(mc.msg_id) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'24 hours\') as hits_last_24h
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = TRUE
            GROUP BY cr.rule_id, cr.campaign_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
        ');

        // Vue pour candidats à la promotion
        $this->addSql('
            CREATE VIEW v_campaign_promotion_candidates AS
            SELECT
                c.campaign_id,
                c.first_seen,
                c.severity,
                cr.rule_id,
                cr.version,
                cr.ppv,
                cr.hits_total,
                cr.hits_true_pos,
                cr.hits_false_pos,
                cr.lead_time_sec,
                COUNT(mc.msg_id) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'24 hours\') as hits_last_24h
            FROM campaign c
            INNER JOIN campaign_rule cr ON c.campaign_id = cr.campaign_id
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE c.status = \'shadow\'
              AND cr.enabled = TRUE
              AND cr.ppv >= 0.85
              AND cr.hits_total >= 5
              AND cr.lead_time_sec >= 10800
              AND cr.promoted_at IS NULL
            GROUP BY c.campaign_id, c.first_seen, c.severity, cr.rule_id, cr.campaign_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
            ORDER BY cr.lead_time_sec DESC NULLS LAST
        ');

        // Vue pour détection de drift PPV (fenêtre glissante 7 jours)
        $this->addSql('
            CREATE VIEW v_campaign_ppv_7d_window AS
            SELECT
                cr.rule_id,
                cr.campaign_id,
                cr.ppv as ppv_lifetime,
                cr.hits_total as hits_lifetime,
                COUNT(mc.msg_id) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\') as hits_7d,
                SUM(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\') as tp_7d,
                SUM(CASE WHEN NOT mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\') as fp_7d,
                CASE
                    WHEN COUNT(mc.msg_id) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\') > 0
                    THEN ROUND(
                        SUM(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\')::numeric /
                        COUNT(mc.msg_id) FILTER (WHERE mc.detected_at >= NOW() - INTERVAL \'7 days\')::numeric,
                        4
                    )
                    ELSE NULL
                END as ppv_7d
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = TRUE
            GROUP BY cr.rule_id, cr.campaign_id, cr.ppv, cr.hits_total
        ');

        $this->addSql('COMMENT ON VIEW v_campaign_ppv_7d_window IS \'PPV drift detection - compare lifetime vs 7-day window metrics\'');

        // Index de performance
        $this->addSql('
            CREATE INDEX idx_campaign_rule_promotion
            ON campaign_rule(campaign_id, ppv DESC, hits_total DESC)
            WHERE enabled = TRUE
        ');

        $this->addSql('
            CREATE INDEX idx_campaign_status_firstseen
            ON campaign(status, first_seen DESC)
            WHERE status IN (\'shadow\', \'promoted\')
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW IF EXISTS v_campaign_ppv_7d_window');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_promotion_candidates');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_shadow_hits');
        $this->addSql('DROP TABLE IF EXISTS actor_profile');
        $this->addSql('DROP TABLE IF EXISTS message_campaign');
        $this->addSql('DROP TABLE IF EXISTS campaign_rule');
        $this->addSql('DROP TABLE IF EXISTS campaign');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
