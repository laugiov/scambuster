<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251024060808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Campaign Radar optimizations: GIN indexes + JSONB type + ARRAY type';
    }

    public function isTransactional(): bool
    {
        // Désactiver les transactions pour CREATE INDEX CONCURRENTLY
        return false;
    }

    public function up(Schema $schema): void
    {
        // 1. Créer index GIN sur message.subject pour performance ILIKE (pg_trgm)
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_subject_trgm ON message USING gin (subject gin_trgm_ops)');

        // 2. Créer index GIN sur message.body_text pour performance ILIKE (pg_trgm)
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_body_trgm ON message USING gin (body_text gin_trgm_ops)');

        // 3. Migrer campaign_rule.compiled_sql de TEXT vers JSONB
        // Étape 3a: Créer une colonne temporaire
        $this->addSql('ALTER TABLE campaign_rule ADD COLUMN compiled_sql_jsonb JSONB NULL');

        // Étape 3b: Migrer les données existantes (TEXT JSON → JSONB)
        // Si la colonne contient du JSON valide, on le convertit, sinon NULL
        $this->addSql("
            UPDATE campaign_rule
            SET compiled_sql_jsonb = CASE
                WHEN compiled_sql IS NULL THEN NULL
                WHEN compiled_sql::text ~ '^\\s*\\{.*\\}\\s*$' THEN compiled_sql::jsonb
                ELSE jsonb_build_object('sql', compiled_sql, 'params', '{}'::jsonb)
            END
        ");

        // Étape 3c: Supprimer l'ancienne colonne
        $this->addSql('ALTER TABLE campaign_rule DROP COLUMN compiled_sql');

        // Étape 3d: Renommer la nouvelle colonne
        $this->addSql('ALTER TABLE campaign_rule RENAME COLUMN compiled_sql_jsonb TO compiled_sql');

        // 4. Migrer actor_profile.campaigns de TEXT vers TEXT[] (ARRAY)
        // Étape 4a: Créer une colonne temporaire
        $this->addSql('ALTER TABLE actor_profile ADD COLUMN campaigns_array TEXT[] NOT NULL DEFAULT \'{}\'');

        // Étape 4b: Migrer les données (SIMPLE_ARRAY format: comma-separated)
        $this->addSql("
            UPDATE actor_profile
            SET campaigns_array = CASE
                WHEN campaigns = '' THEN '{}'::TEXT[]
                ELSE string_to_array(campaigns, ',')
            END
        ");

        // Étape 4c: Supprimer l'ancienne colonne
        $this->addSql('ALTER TABLE actor_profile DROP COLUMN campaigns');

        // Étape 4d: Renommer la nouvelle colonne
        $this->addSql('ALTER TABLE actor_profile RENAME COLUMN campaigns_array TO campaigns');
    }

    public function down(Schema $schema): void
    {
        // 1. Supprimer index GIN sur message.subject
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_message_subject_trgm');

        // 2. Supprimer index GIN sur message.body_text
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_message_body_trgm');

        // 3. Revenir de JSONB à TEXT pour campaign_rule.compiled_sql
        $this->addSql('ALTER TABLE campaign_rule ADD COLUMN compiled_sql_text TEXT NULL');

        $this->addSql("
            UPDATE campaign_rule
            SET compiled_sql_text = CASE
                WHEN compiled_sql IS NULL THEN NULL
                ELSE compiled_sql::text
            END
        ");

        $this->addSql('ALTER TABLE campaign_rule DROP COLUMN compiled_sql');
        $this->addSql('ALTER TABLE campaign_rule RENAME COLUMN compiled_sql_text TO compiled_sql');

        // 4. Revenir de TEXT[] à TEXT pour actor_profile.campaigns
        $this->addSql('ALTER TABLE actor_profile ADD COLUMN campaigns_text TEXT NOT NULL DEFAULT \'\'');

        $this->addSql("
            UPDATE actor_profile
            SET campaigns_text = CASE
                WHEN campaigns = '{}'::TEXT[] THEN ''
                ELSE array_to_string(campaigns, ',')
            END
        ");

        $this->addSql('ALTER TABLE actor_profile DROP COLUMN campaigns');
        $this->addSql('ALTER TABLE actor_profile RENAME COLUMN campaigns_text TO campaigns');
    }
}
