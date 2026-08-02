<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the prompt_canary_job table backing the asynchronous "validate this prompt" flow: a
 * job carries an UNSAVED candidate prompt body, is drained by the dedicated canary worker, and
 * stores the verdict for the UI to poll. The candidate is never activated as a real override.
 *
 * Purely additive: one new table + a (status, created_at) index for the worker's claim query.
 * It touches no existing table and removes no data, so it is safe on a populated production DB.
 */
final class Version2026072900000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create prompt_canary_job table for async prompt validation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE prompt_canary_job (id SERIAL NOT NULL, prompt_key VARCHAR(64) NOT NULL, candidate_body TEXT NOT NULL, status VARCHAR(20) NOT NULL, verdict JSON DEFAULT NULL, error TEXT DEFAULT NULL, requested_by VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_prompt_canary_job_status_created ON prompt_canary_job (status, created_at)');
        $this->addSql("COMMENT ON COLUMN prompt_canary_job.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN prompt_canary_job.started_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN prompt_canary_job.finished_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE prompt_canary_job');
    }
}
