<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create bandit_convergence_log table for daily convergence snapshots.
 */
final class Version20260324180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bandit_convergence_log table for tracking persona convergence over time';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bandit_convergence_log (
            id SERIAL PRIMARY KEY,
            scam_type_code VARCHAR(32) NOT NULL,
            dominant_persona_code VARCHAR(32) NOT NULL,
            dominant_pct NUMERIC(5,2) NOT NULL,
            sessions_count INT NOT NULL,
            converged BOOLEAN NOT NULL DEFAULT FALSE,
            logged_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');

        $this->addSql('CREATE INDEX idx_convergence_scam_type ON bandit_convergence_log(scam_type_code)');
        $this->addSql('CREATE INDEX idx_convergence_logged_at ON bandit_convergence_log(logged_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bandit_convergence_log');
    }
}
