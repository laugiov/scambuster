<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create llm_usage table for LLM cost tracking.
 */
final class Version20260322080442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create llm_usage table for LLM cost tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE SEQUENCE llm_usage_id_seq INCREMENT BY 1 MINVALUE 1 START 1
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE llm_usage (
                id INT NOT NULL DEFAULT nextval('llm_usage_id_seq'),
                conversation_id VARCHAR(36) DEFAULT NULL,
                provider VARCHAR(32) NOT NULL,
                model VARCHAR(64) NOT NULL,
                purpose VARCHAR(50) NOT NULL,
                prompt_tokens INT NOT NULL,
                completion_tokens INT NOT NULL,
                estimated_cost_usd NUMERIC(10, 6) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_llm_usage_created_at ON llm_usage (created_at)');
        $this->addSql('CREATE INDEX idx_llm_usage_provider ON llm_usage (provider)');
        $this->addSql("COMMENT ON COLUMN llm_usage.created_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE llm_usage_id_seq CASCADE');
        $this->addSql('DROP TABLE llm_usage');
    }
}
