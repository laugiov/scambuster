<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Add scambaiting metrics columns to conversation table
 *
 * Adds:
 * - engagement_duration_sec (INTEGER NOT NULL DEFAULT 0)
 * - turns_count (INTEGER NOT NULL DEFAULT 0)
 * - reward_value (NUMERIC(5,4) NULL)
 */
final class Version20251121100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scambaiting adaptive metrics to conversation table (engagement_duration_sec, turns_count, reward_value)';
    }

    public function up(Schema $schema): void
    {
        // Add columns
        $this->addSql('
            ALTER TABLE conversation
            ADD COLUMN engagement_duration_sec INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN turns_count INTEGER NOT NULL DEFAULT 0,
            ADD COLUMN reward_value NUMERIC(5,4) DEFAULT NULL
        ');

        // Add comments
        $this->addSql("
            COMMENT ON COLUMN conversation.engagement_duration_sec IS 'Durée engagement en secondes (ts_last - ts_first), max 172800 (48h)'
        ");
        $this->addSql("
            COMMENT ON COLUMN conversation.turns_count IS 'Nombre de tours de parole (messages inbound + outbound)'
        ");
        $this->addSql("
            COMMENT ON COLUMN conversation.reward_value IS 'Reward normalisé 0-1 calculé par formule: 0.40×dur + 0.25×iocs + 0.25×sens + 0.10×compl'
        ");

        // Add indexes (optional but recommended for performance)
        $this->addSql('
            CREATE INDEX idx_conversation_reward ON conversation(reward_value) WHERE reward_value IS NOT NULL
        ');
        $this->addSql('
            CREATE INDEX idx_conversation_duration ON conversation(engagement_duration_sec)
        ');
    }

    public function down(Schema $schema): void
    {
        // Drop indexes first
        $this->addSql('DROP INDEX IF EXISTS idx_conversation_reward');
        $this->addSql('DROP INDEX IF EXISTS idx_conversation_duration');

        // Drop columns
        $this->addSql('
            ALTER TABLE conversation
            DROP COLUMN IF EXISTS engagement_duration_sec,
            DROP COLUMN IF EXISTS turns_count,
            DROP COLUMN IF EXISTS reward_value
        ');
    }
}
