<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251028015912 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conversation.persona_id + Fix UUID types (requires DROP/RECREATE views)';
    }

    public function up(Schema $schema): void
    {
        // 1. DROP views that depend on campaign_id, campaign_rule, message_campaign
        $this->addSql('DROP VIEW IF EXISTS v_campaign_promotion_candidates CASCADE');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_ppv_7d_window CASCADE');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_shadow_hits CASCADE');

        // 2. Perform schema modifications
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER actor_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER actor_id DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER created_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER updated_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER campaigns TYPE TEXT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER campaigns DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.actor_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.created_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.updated_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.campaigns IS '(DC2Type:simple_array)'
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_status_firstseen
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER campaign_id DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER first_seen TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER first_seen DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER tlp DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER severity DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER created_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER updated_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.campaign_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.first_seen IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.profile_yaml IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.centroid_simhash IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.created_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.updated_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule DROP CONSTRAINT IF EXISTS campaign_rule_campaign_id_fkey
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_rule_promotion
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_rule_ppv
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_rule_enabled
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER rule_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER rule_id DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER version DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER ppv DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_total DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_true_pos DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_false_pos DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER promoted_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER enabled DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER created_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER updated_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.rule_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.campaign_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.promoted_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.created_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.updated_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_rule_ppv ON campaign_rule (ppv)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_rule_enabled ON campaign_rule (enabled)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD persona_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD CONSTRAINT FK_8A8E26E9F5F88DB9 FOREIGN KEY (persona_id) REFERENCES persona (persona_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_8A8E26E9F5F88DB9 ON conversation (persona_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona DROP CONSTRAINT IF EXISTS fk_scam_type_persona_scam_type
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona DROP CONSTRAINT IF EXISTS fk_scam_type_persona_persona
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona ADD CONSTRAINT FK_241C103A618DE68 FOREIGN KEY (scam_type_id) REFERENCES lkp_scam_type (scam_type_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona ADD CONSTRAINT FK_241C103F5F88DB9 FOREIGN KEY (persona_id) REFERENCES persona (persona_id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_message_body_trgm
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_message_subject_trgm
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_message_external_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign DROP CONSTRAINT IF EXISTS message_campaign_msg_id_fkey
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign DROP CONSTRAINT IF EXISTS message_campaign_campaign_id_fkey
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_message_campaign_features
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS IDX_1633D6CC842BF4A0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER msg_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER detected_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER detected_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.msg_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.campaign_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.detected_at IS '(DC2Type:datetimetz_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.features IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.is_true_positive IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS uniq_obs_msg_type_valnorm
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER persona_id DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_by DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_at DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER is_active DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX persona_persona_code_key RENAME TO uniq_persona_code
        SQL);

        // 3. RECREATE views that were dropped
        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_ppv_7d_window AS
            SELECT cr.rule_id,
                   cr.campaign_id,
                   cr.ppv AS ppv_lifetime,
                   cr.hits_total AS hits_lifetime,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS hits_7d,
                   sum(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS tp_7d,
                   sum(CASE WHEN NOT mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS fp_7d,
                   CASE WHEN count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) > 0
                        THEN round(sum(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval))::numeric /
                                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval))::numeric, 4)
                        ELSE NULL::numeric
                   END AS ppv_7d
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = true
            GROUP BY cr.rule_id, cr.campaign_id, cr.ppv, cr.hits_total
        SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_shadow_hits AS
            SELECT cr.rule_id,
                   cr.campaign_id,
                   cr.version,
                   cr.ppv,
                   cr.hits_total,
                   cr.hits_true_pos,
                   cr.hits_false_pos,
                   cr.lead_time_sec,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '24:00:00'::interval)) AS hits_last_24h
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = true
            GROUP BY cr.rule_id, cr.campaign_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
        SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_promotion_candidates AS
            SELECT c.campaign_id,
                   c.first_seen,
                   c.severity,
                   cr.rule_id,
                   cr.version,
                   cr.ppv,
                   cr.hits_total,
                   cr.hits_true_pos,
                   cr.hits_false_pos,
                   cr.lead_time_sec,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '24:00:00'::interval)) AS hits_last_24h
            FROM campaign c
            JOIN campaign_rule cr ON c.campaign_id = cr.campaign_id
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE c.status::text = 'shadow'::text
              AND cr.enabled = true
              AND cr.ppv >= 0.85
              AND cr.hits_total >= 5
              AND cr.lead_time_sec >= 10800
              AND cr.promoted_at IS NULL
            GROUP BY c.campaign_id, c.first_seen, c.severity, cr.rule_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
        SQL);
    }

    public function down(Schema $schema): void
    {
        // 1. DROP views before modifying columns
        $this->addSql('DROP VIEW IF EXISTS v_campaign_promotion_candidates CASCADE');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_ppv_7d_window CASCADE');
        $this->addSql('DROP VIEW IF EXISTS v_campaign_shadow_hits CASCADE');

        // 2. Revert schema modifications
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_message_body_trgm ON message (body_text)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_message_subject_trgm ON message (subject)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_message_external_id ON message (external_message_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE persona_persona_id_seq
        SQL);
        $this->addSql(<<<'SQL'
            SELECT setval('persona_persona_id_seq', (SELECT MAX(persona_id) FROM persona))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER persona_id SET DEFAULT nextval('persona_persona_id_seq')
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_by SET DEFAULT 'manual'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER created_at SET DEFAULT CURRENT_TIMESTAMP
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona ALTER is_active SET DEFAULT true
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona.created_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX uniq_persona_code RENAME TO persona_persona_code_key
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER actor_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER actor_id SET DEFAULT 'gen_random_uuid()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER campaigns TYPE VARCHAR(255)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER campaigns SET DEFAULT '{}'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER created_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE actor_profile ALTER updated_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.actor_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.campaigns IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.created_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN actor_profile.updated_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER msg_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER detected_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ALTER detected_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.msg_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.campaign_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.detected_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.features IS 'Features clustering (text/infra/style)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN message_campaign.is_true_positive IS 'True=TP, False=FP, Null=non validé'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ADD CONSTRAINT message_campaign_msg_id_fkey FOREIGN KEY (msg_id) REFERENCES message (msg_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE message_campaign ADD CONSTRAINT message_campaign_campaign_id_fkey FOREIGN KEY (campaign_id) REFERENCES campaign (campaign_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_message_campaign_features ON message_campaign (features)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_1633D6CC842BF4A0 ON message_campaign (msg_id)
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_rule_enabled
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS idx_campaign_rule_ppv
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER rule_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER rule_id SET DEFAULT 'gen_random_uuid()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER version SET DEFAULT 1
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER ppv SET DEFAULT '0.0'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_total SET DEFAULT 0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_true_pos SET DEFAULT 0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER hits_false_pos SET DEFAULT 0
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER promoted_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER enabled SET DEFAULT true
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER created_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ALTER updated_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.rule_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.campaign_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.promoted_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.created_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign_rule.updated_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign_rule ADD CONSTRAINT campaign_rule_campaign_id_fkey FOREIGN KEY (campaign_id) REFERENCES campaign (campaign_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_rule_promotion ON campaign_rule (campaign_id, ppv, hits_total) WHERE (enabled = true)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_rule_enabled ON campaign_rule (enabled) WHERE (enabled = true)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_rule_ppv ON campaign_rule (ppv) WHERE (enabled = true)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER campaign_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER campaign_id SET DEFAULT 'gen_random_uuid()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER first_seen TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER first_seen SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER tlp SET DEFAULT 'TLP:AMBER'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER severity SET DEFAULT 2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER created_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER updated_at TYPE TIMESTAMP(0) WITH TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE campaign ALTER updated_at SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.campaign_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.first_seen IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.profile_yaml IS 'YAML profile généré par LLM (CampaignProfiler)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.centroid_simhash IS 'Simhash MD5 centroid pour clustering similarity'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.created_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN campaign.updated_at IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_campaign_status_firstseen ON campaign (status, first_seen) WHERE ((status)::text = ANY ((ARRAY['shadow'::character varying, 'promoted'::character varying])::text[]))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona DROP CONSTRAINT FK_241C103A618DE68
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona DROP CONSTRAINT FK_241C103F5F88DB9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona ADD CONSTRAINT fk_scam_type_persona_scam_type FOREIGN KEY (scam_type_id) REFERENCES lkp_scam_type (scam_type_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE scam_type_persona ADD CONSTRAINT fk_scam_type_persona_persona FOREIGN KEY (persona_id) REFERENCES persona (persona_id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_obs_msg_type_valnorm ON observed_ioc (msg_id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP CONSTRAINT FK_8A8E26E9F5F88DB9
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IF EXISTS IDX_8A8E26E9F5F88DB9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP persona_id
        SQL);

        // 3. RECREATE views with original state (before UUID type changes)
        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_ppv_7d_window AS
            SELECT cr.rule_id,
                   cr.campaign_id,
                   cr.ppv AS ppv_lifetime,
                   cr.hits_total AS hits_lifetime,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS hits_7d,
                   sum(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS tp_7d,
                   sum(CASE WHEN NOT mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) AS fp_7d,
                   CASE WHEN count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval)) > 0
                        THEN round(sum(CASE WHEN mc.is_true_positive THEN 1 ELSE 0 END) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval))::numeric /
                                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '7 days'::interval))::numeric, 4)
                        ELSE NULL::numeric
                   END AS ppv_7d
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = true
            GROUP BY cr.rule_id, cr.campaign_id, cr.ppv, cr.hits_total
        SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_shadow_hits AS
            SELECT cr.rule_id,
                   cr.campaign_id,
                   cr.version,
                   cr.ppv,
                   cr.hits_total,
                   cr.hits_true_pos,
                   cr.hits_false_pos,
                   cr.lead_time_sec,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '24:00:00'::interval)) AS hits_last_24h
            FROM campaign_rule cr
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE cr.enabled = true
            GROUP BY cr.rule_id, cr.campaign_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
        SQL);

        $this->addSql(<<<'SQL'
            CREATE VIEW v_campaign_promotion_candidates AS
            SELECT c.campaign_id,
                   c.first_seen,
                   c.severity,
                   cr.rule_id,
                   cr.version,
                   cr.ppv,
                   cr.hits_total,
                   cr.hits_true_pos,
                   cr.hits_false_pos,
                   cr.lead_time_sec,
                   count(mc.msg_id) FILTER (WHERE mc.detected_at >= (now() - '24:00:00'::interval)) AS hits_last_24h
            FROM campaign c
            JOIN campaign_rule cr ON c.campaign_id = cr.campaign_id
            LEFT JOIN message_campaign mc ON cr.campaign_id = mc.campaign_id
            WHERE c.status::text = 'shadow'::text
              AND cr.enabled = true
              AND cr.ppv >= 0.85
              AND cr.hits_total >= 5
              AND cr.lead_time_sec >= 10800
              AND cr.promoted_at IS NULL
            GROUP BY c.campaign_id, c.first_seen, c.severity, cr.rule_id, cr.version, cr.ppv, cr.hits_total, cr.hits_true_pos, cr.hits_false_pos, cr.lead_time_sec
        SQL);
    }
}
