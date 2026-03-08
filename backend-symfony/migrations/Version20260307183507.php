<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260307183507 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP INDEX idx_conversation_duration
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_conversation_reward
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD delivery VARCHAR(32) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation ADD tlp VARCHAR(16) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.engagement_duration_sec IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.turns_count IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.reward_value IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD label VARCHAR(128) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD description TEXT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD misp_taxonomy VARCHAR(128) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD active BOOLEAN NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP label_en
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP label_fr
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type RENAME COLUMN attack_id TO attck_technique
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN lkp_scam_type.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN lkp_scam_type.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc ALTER indicator_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.indicator_id IS '(DC2Type:uuid)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.context_observation IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_persona_perf_updated
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona_performance_stats ALTER last_updated TYPE TIMESTAMP(0) WITHOUT TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona_performance_stats ALTER last_updated DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.persona_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.scam_type_id IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.sessions_count IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.reward_sum IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.reward_avg IS NULL
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.last_updated IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX idx_persona_perf_reward_desc RENAME TO idx_persona_performance_reward
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX idx_persona_perf_scam_type RENAME TO idx_persona_performance_scam_type
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP delivery
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation DROP tlp
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.engagement_duration_sec IS 'Durée engagement en secondes (ts_last - ts_first), max 172800 (48h)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.turns_count IS 'Nombre de tours de parole (messages inbound + outbound)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN conversation.reward_value IS 'Reward normalisé 0-1 calculé par formule: 0.40×dur + 0.25×iocs + 0.25×sens + 0.10×compl'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_conversation_duration ON conversation (engagement_duration_sec)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_conversation_reward ON conversation (reward_value) WHERE (reward_value IS NOT NULL)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona_performance_stats ALTER last_updated TYPE TIMESTAMP(0) WITHOUT TIME ZONE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE persona_performance_stats ALTER last_updated SET DEFAULT 'now()'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.persona_id IS 'ID du persona (FK vers persona)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.scam_type_id IS 'ID du type de scam (FK vers scam_type)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.sessions_count IS 'Nombre de conversations complétées avec ce (persona, scam_type)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.reward_sum IS 'Somme cumulée des rewards (pour calcul moyenne mobile)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.reward_avg IS 'Moyenne mobile des rewards (0-1), mise à jour incrémentale'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN persona_performance_stats.last_updated IS 'Timestamp dernière mise à jour (pour audit)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_persona_perf_updated ON persona_performance_stats (last_updated)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX idx_persona_performance_reward RENAME TO idx_persona_perf_reward_desc
        SQL);
        $this->addSql(<<<'SQL'
            ALTER INDEX idx_persona_performance_scam_type RENAME TO idx_persona_perf_scam_type
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD label_en VARCHAR(64) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type ADD label_fr VARCHAR(64) NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP label
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP description
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP misp_taxonomy
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP active
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP created_at
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type DROP updated_at
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE lkp_scam_type RENAME COLUMN attck_technique TO attack_id
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE observed_ioc ALTER indicator_id TYPE UUID
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.indicator_id IS 'UUID de l''indicateur IOC (FK logique vers table ioc)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN observed_ioc.context_observation IS 'Contexte JSON de l''observation (position dans message, métadonnées extraction)'
        SQL);
    }
}
