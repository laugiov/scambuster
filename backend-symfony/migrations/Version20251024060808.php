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
        // Disable transactions for CREATE INDEX CONCURRENTLY
        return false;
    }

    public function up(Schema $schema): void
    {
        // 1. Create GIN index on message.subject for ILIKE performance (pg_trgm)
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_subject_trgm ON message USING gin (subject gin_trgm_ops)');

        // 2. Create GIN index on message.body_text for ILIKE performance (pg_trgm)
        $this->addSql('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_message_body_trgm ON message USING gin (body_text gin_trgm_ops)');

        // 3. Migrate campaign_rule.compiled_sql from TEXT to JSONB
        // Step 3a: Create a temporary column
        $this->addSql('ALTER TABLE campaign_rule ADD COLUMN compiled_sql_jsonb JSONB NULL');

        // Step 3b: Migrate existing data (TEXT JSON → JSONB)
        // If the column contains valid JSON, convert it, otherwise NULL
        $this->addSql("
            UPDATE campaign_rule
            SET compiled_sql_jsonb = CASE
                WHEN compiled_sql IS NULL THEN NULL
                WHEN compiled_sql::text ~ '^\\s*\\{.*\\}\\s*$' THEN compiled_sql::jsonb
                ELSE jsonb_build_object('sql', compiled_sql, 'params', '{}'::jsonb)
            END
        ");

        // Step 3c: Drop the old column
        $this->addSql('ALTER TABLE campaign_rule DROP COLUMN compiled_sql');

        // Step 3d: Rename the new column
        $this->addSql('ALTER TABLE campaign_rule RENAME COLUMN compiled_sql_jsonb TO compiled_sql');

        // 4. Migrate actor_profile.campaigns from TEXT to TEXT[] (ARRAY)
        // Step 4a: Create a temporary column
        $this->addSql('ALTER TABLE actor_profile ADD COLUMN campaigns_array TEXT[] NOT NULL DEFAULT \'{}\'');

        // Step 4b: Migrate the data (SIMPLE_ARRAY format: comma-separated)
        $this->addSql("
            UPDATE actor_profile
            SET campaigns_array = CASE
                WHEN campaigns = '' THEN '{}'::TEXT[]
                ELSE string_to_array(campaigns, ',')
            END
        ");

        // Step 4c: Drop the old column
        $this->addSql('ALTER TABLE actor_profile DROP COLUMN campaigns');

        // Step 4d: Rename the new column
        $this->addSql('ALTER TABLE actor_profile RENAME COLUMN campaigns_array TO campaigns');
    }

    public function down(Schema $schema): void
    {
        // 1. Drop GIN index on message.subject
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_message_subject_trgm');

        // 2. Drop GIN index on message.body_text
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_message_body_trgm');

        // 3. Revert from JSONB to TEXT for campaign_rule.compiled_sql
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

        // 4. Revert from TEXT[] to TEXT for actor_profile.campaigns
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
